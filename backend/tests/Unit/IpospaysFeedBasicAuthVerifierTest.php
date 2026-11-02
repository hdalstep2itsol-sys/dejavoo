<?php

namespace Tests\Unit;

use App\Enums\IpospaysFeedBasicAuthStatus;
use App\Services\IpospaysFeedBasicAuthVerifier;
use Illuminate\Http\Request;
use Tests\TestCase;

class IpospaysFeedBasicAuthVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.basic_username', 'dejavoo_feed');
        config()->set('ipospays.feed.basic_password', 'test-password');
    }

    public function test_valid_credentials_are_accepted(): void
    {
        $result = $this->verify('Basic '.base64_encode('dejavoo_feed:test-password'));

        $this->assertSame(IpospaysFeedBasicAuthStatus::Verified, $result->status);
        $this->assertTrue($result->headerPresent);
        $this->assertTrue($result->usernamePresent);
    }

    public function test_missing_malformed_and_invalid_credentials_fail_closed(): void
    {
        $this->assertSame(IpospaysFeedBasicAuthStatus::Missing, $this->verify()->status);
        $this->assertSame(IpospaysFeedBasicAuthStatus::Malformed, $this->verify('Bearer token')->status);
        $this->assertSame(IpospaysFeedBasicAuthStatus::Malformed, $this->verify('Basic !!!')->status);
        $this->assertSame(
            IpospaysFeedBasicAuthStatus::Malformed,
            $this->verify('Basic '.base64_encode('missing-separator'))->status,
        );
        $this->assertSame(
            IpospaysFeedBasicAuthStatus::Invalid,
            $this->verify('Basic '.base64_encode('wrong:test-password'))->status,
        );
        $this->assertSame(
            IpospaysFeedBasicAuthStatus::Invalid,
            $this->verify('Basic '.base64_encode('dejavoo_feed:wrong'))->status,
        );
    }

    public function test_missing_server_credentials_and_disabled_feed_fail_closed(): void
    {
        config()->set('ipospays.feed.basic_password');
        $this->assertSame(
            IpospaysFeedBasicAuthStatus::CredentialsMissing,
            $this->verify('Basic '.base64_encode('dejavoo_feed:test-password'))->status,
        );

        config()->set('ipospays.feed.enabled', false);
        $this->assertSame(
            IpospaysFeedBasicAuthStatus::FeedDisabled,
            $this->verify('Basic '.base64_encode('dejavoo_feed:test-password'))->status,
        );
    }

    private function verify(?string $authorization = null)
    {
        $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];

        return app(IpospaysFeedBasicAuthVerifier::class)->verify(
            Request::create('/api/webhooks/ipospays/feed', 'POST', server: $server),
        );
    }
}
