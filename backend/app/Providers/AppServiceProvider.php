<?php

namespace App\Providers;

use App\Mail\GmailApiTransport;
use App\Services\DejavooFeedV1HmacProfile;
use App\Services\IpospaysFeedHmacVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IpospaysFeedHmacVerifier::class, function (Application $app) {
            $profile = $app->make(DejavooFeedV1HmacProfile::class);

            return new IpospaysFeedHmacVerifier([
                $profile->name() => $profile,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('gmail-api', function (array $config): GmailApiTransport {
            return new GmailApiTransport(
                app(HttpFactory::class),
                app(LoggerInterface::class),
                (string) ($config['client_id'] ?? ''),
                (string) ($config['client_secret'] ?? ''),
                (string) ($config['refresh_token'] ?? ''),
                (string) ($config['sender_email'] ?? ''),
            );
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
