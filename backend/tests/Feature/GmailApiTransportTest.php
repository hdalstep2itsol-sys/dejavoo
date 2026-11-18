<?php

namespace Tests\Feature;

use App\Mail\GmailApiTransport;
use App\Notifications\IpospaysFeedFailureAlert;
use App\Notifications\TrailerLoadThresholdReached;
use App\Notifications\WarehouseLoadPending;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class GmailApiTransportTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';

    private const CLIENT_SECRET = 'test-client-secret';

    private const REFRESH_TOKEN = 'test-refresh-token';

    private const SENDER = 'springback.logistics@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'mail.default' => 'gmail-api',
            'mail.mailers.gmail-api' => [
                'transport' => 'gmail-api',
                'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET,
                'refresh_token' => self::REFRESH_TOKEN,
                'sender_email' => self::SENDER,
            ],
            'mail.from.address' => self::SENDER,
            'mail.from.name' => 'Spring Back Logistics Hub',
        ]);

        Mail::purge();
    }

    public function test_existing_laravel_notification_sends_mime_message_through_gmail_api(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-message-id',
            ]),
        ]);

        Notification::route('mail', 'operations@example.test')->notifyNow(
            new TrailerLoadThresholdReached(42, 'Nashville', '71.5', '70'),
        );

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            return $request->data() === [
                'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET,
                'refresh_token' => self::REFRESH_TOKEN,
                'grant_type' => 'refresh_token',
            ];
        });

        $gmailRequests = Http::recorded(
            fn (Request $request): bool => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        );

        $this->assertCount(1, $gmailRequests);

        /** @var Request $gmailRequest */
        $gmailRequest = $gmailRequests->first()[0];
        $mime = $this->decodeRawMessage((string) ($gmailRequest->data()['raw'] ?? ''));
        $decodedMime = quoted_printable_decode($mime);

        $this->assertTrue($gmailRequest->hasHeader('Authorization', 'Bearer access-token'));
        $this->assertStringContainsString('To: operations@example.test', $mime);
        $this->assertStringContainsString('Spring Back Logistics Hub', $mime);
        $this->assertStringContainsString(self::SENDER, $mime);
        $this->assertStringContainsString('Subject: Trailer threshold reached: Nashville', $mime);
        $this->assertStringContainsString('Content-Type: text/plain', $mime);
        $this->assertStringContainsString('Content-Type: text/html', $mime);
        $this->assertStringContainsString('Operational units: 71.5', $decodedMime);

        Http::assertSentCount(2);
    }

    public function test_access_token_is_reused_until_expiry(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'reusable-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-message-id',
            ]),
        ]);

        $transport = $this->transport(new RecordingLogger);
        $transport->send($this->email('First message'));
        $transport->send($this->email('Second message'));

        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token',
        ));
    }

    public function test_warehouse_and_feed_failure_notifications_use_the_same_transport(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'id' => 'gmail-message-id',
            ]),
        ]);

        Notification::route('mail', 'warehouse@example.test')->notifyNow(
            new WarehouseLoadPending(51, 'Knoxville', '2026-11-18 10:00:00 UTC'),
        );
        Notification::route('mail', 'operations@example.test')->notifyNow(
            new IpospaysFeedFailureAlert(3, 900, 'transaction_normalization', '2026-11-18 10:05:00 UTC'),
        );

        $mimeMessages = Http::recorded(
            fn (Request $request): bool => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        )->map(fn (array $record): string => $this->decodeRawMessage(
            (string) ($record[0]->data()['raw'] ?? ''),
        ));

        $this->assertCount(2, $mimeMessages);
        $this->assertTrue($mimeMessages->contains(
            fn (string $mime): bool => str_contains($mime, 'Subject: Warehouse count pending: Knoxville'),
        ));
        $this->assertTrue($mimeMessages->contains(
            fn (string $mime): bool => str_contains($mime, 'Subject: iPOSpays FEED processing warning'),
        ));
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token',
        ));
    }

    public function test_unauthorized_access_token_is_refreshed_once_before_retry(): void
    {
        $tokenRequests = 0;
        $sendRequests = 0;

        Http::fake(function (Request $request) use (&$tokenRequests, &$sendRequests) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'access-token-'.$tokenRequests,
                    'expires_in' => 3600,
                ]);
            }

            $sendRequests++;

            return $sendRequests === 1
                ? Http::response(['error' => 'unauthorized'], 401)
                : Http::response(['id' => 'gmail-message-id']);
        });

        $this->transport(new RecordingLogger)->send($this->email('Token refresh'));

        $this->assertSame(2, $tokenRequests);
        $this->assertSame(2, $sendRequests);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
            && $request->hasHeader('Authorization', 'Bearer access-token-2'));
    }

    public function test_authentication_failure_is_safe_and_does_not_send_message(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => self::REFRESH_TOKEN,
            ], 400),
        ]);

        $logger = new RecordingLogger;

        try {
            $this->transport($logger)->send($this->email('Authentication failure'));
            $this->fail('Expected a Gmail authentication failure.');
        } catch (TransportException $exception) {
            $this->assertSame('Gmail API authentication failed (HTTP 400).', $exception->getMessage());
            $this->assertSafeDiagnostics($logger, $exception);
        }

        Http::assertSentCount(1);
    }

    public function test_rate_limit_is_reported_safely_for_queue_retry_handling(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([
                'error' => self::CLIENT_SECRET,
            ], 429, ['Retry-After' => '120']),
        ]);

        $logger = new RecordingLogger;

        try {
            $this->transport($logger)->send($this->email('Rate limit'));
            $this->fail('Expected a Gmail rate-limit failure.');
        } catch (TransportException $exception) {
            $this->assertSame('Gmail API rate limited the delivery request (HTTP 429).', $exception->getMessage());
            $this->assertSafeDiagnostics($logger, $exception);
            $this->assertSame(120, $logger->records[0]['context']['retry_after_seconds']);
        }
    }

    public function test_network_failure_is_wrapped_without_exposing_credentials(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::failedConnection('network unavailable'),
        ]);

        $logger = new RecordingLogger;

        try {
            $this->transport($logger)->send($this->email('Network failure'));
            $this->fail('Expected a Gmail network failure.');
        } catch (TransportException $exception) {
            $this->assertSame(
                'Gmail API delivery failed because the service could not be reached.',
                $exception->getMessage(),
            );
            $this->assertSafeDiagnostics($logger, $exception);
        }
    }

    private function transport(RecordingLogger $logger): GmailApiTransport
    {
        return new GmailApiTransport(
            app(HttpFactory::class),
            $logger,
            self::CLIENT_ID,
            self::CLIENT_SECRET,
            self::REFRESH_TOKEN,
            self::SENDER,
        );
    }

    private function email(string $subject): Email
    {
        return (new Email)
            ->from(self::SENDER)
            ->to('operations@example.test')
            ->subject($subject)
            ->text('Plain text content')
            ->html('<p>HTML content</p>');
    }

    private function decodeRawMessage(string $raw): string
    {
        $base64 = strtr($raw, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);

        return (string) base64_decode($base64, true);
    }

    private function assertSafeDiagnostics(RecordingLogger $logger, TransportException $exception): void
    {
        $diagnostics = $exception->getMessage().' '.json_encode($logger->records);

        $this->assertStringNotContainsString(self::CLIENT_ID, $diagnostics);
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $diagnostics);
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, $diagnostics);
        $this->assertStringNotContainsString('operations@example.test', $diagnostics);
        $this->assertStringNotContainsString('Plain text content', $diagnostics);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
