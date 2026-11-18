<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

final class GmailApiTransport extends AbstractTransport
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly HttpFactory $http,
        LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly string $senderEmail,
    ) {
        parent::__construct(logger: $logger);

        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new InvalidArgumentException('The Gmail API mailer is missing required OAuth configuration.');
        }

        if (! filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The Gmail API mailer requires a valid sender email address.');
        }
    }

    protected function doSend(SentMessage $message): void
    {
        $this->ensureConfiguredSender($message);

        try {
            $response = $this->sendMessage($message, $this->getAccessToken());

            if ($response->status() === 401) {
                $this->getLogger()->warning('Gmail API rejected an access token; refreshing once.', [
                    'mail_transport' => 'gmail-api',
                    'stage' => 'message_send',
                    'status' => 401,
                ]);

                $this->forgetAccessToken();
                $response = $this->sendMessage($message, $this->getAccessToken());
            }

            $this->assertMessageWasSent($response);

            $messageId = $response->json('id');

            if (is_string($messageId) && $messageId !== '') {
                $message->setMessageId($messageId);
            }
        } catch (TransportException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            $this->logUnexpectedFailure('message_send', $exception);

            throw new TransportException('Gmail API delivery failed because the service could not be reached.');
        } catch (Throwable $exception) {
            $this->logUnexpectedFailure('message_send', $exception);

            throw new TransportException('Gmail API delivery failed unexpectedly.');
        }
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::TOKEN_ENDPOINT, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (ConnectionException $exception) {
            $this->logUnexpectedFailure('access_token', $exception);

            throw new TransportException('Gmail API authentication failed because Google could not be reached.');
        } catch (Throwable $exception) {
            $this->logUnexpectedFailure('access_token', $exception);

            throw new TransportException('Gmail API authentication failed unexpectedly.');
        }

        if (! $response->successful()) {
            $this->getLogger()->error('Gmail API access token exchange failed.', [
                'mail_transport' => 'gmail-api',
                'stage' => 'access_token',
                'status' => $response->status(),
            ]);

            throw new TransportException(sprintf(
                'Gmail API authentication failed (HTTP %d).',
                $response->status(),
            ));
        }

        $accessToken = $response->json('access_token');
        $expiresIn = $response->json('expires_in', 3600);

        if (! is_string($accessToken) || $accessToken === '') {
            $this->getLogger()->error('Gmail API access token response was invalid.', [
                'mail_transport' => 'gmail-api',
                'stage' => 'access_token',
                'status' => $response->status(),
            ]);

            throw new TransportException('Gmail API authentication returned an invalid response.');
        }

        $this->accessToken = $accessToken;
        $this->accessTokenExpiresAt = time() + max(1, (int) $expiresIn - 60);

        return $this->accessToken;
    }

    private function forgetAccessToken(): void
    {
        $this->accessToken = null;
        $this->accessTokenExpiresAt = 0;
    }

    private function sendMessage(SentMessage $message, string $accessToken): Response
    {
        return $this->http
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->post(self::SEND_ENDPOINT, [
                'raw' => $this->base64UrlEncode($message->toString()),
            ]);
    }

    private function assertMessageWasSent(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $context = [
            'mail_transport' => 'gmail-api',
            'stage' => 'message_send',
            'status' => $response->status(),
        ];

        if ($response->status() === 429) {
            $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

            if ($retryAfter !== false) {
                $context['retry_after_seconds'] = $retryAfter;
            }

            $this->getLogger()->warning('Gmail API rate limited a mail delivery request.', $context);

            throw new TransportException('Gmail API rate limited the delivery request (HTTP 429).');
        }

        $this->getLogger()->error('Gmail API mail delivery request failed.', $context);

        throw new TransportException(sprintf(
            'Gmail API delivery failed (HTTP %d).',
            $response->status(),
        ));
    }

    private function ensureConfiguredSender(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $configuredSender = strtolower($this->senderEmail);

        foreach ($email->getFrom() as $from) {
            if (strtolower($from->getAddress()) === $configuredSender) {
                return;
            }
        }

        throw new TransportException('Gmail API message sender does not match the configured sender account.');
    }

    private function base64UrlEncode(string $message): string
    {
        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }

    private function logUnexpectedFailure(string $stage, Throwable $exception): void
    {
        $this->getLogger()->error('Gmail API transport encountered an unexpected failure.', [
            'mail_transport' => 'gmail-api',
            'stage' => $stage,
            'exception_class' => $exception::class,
        ]);
    }
}
