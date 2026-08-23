<?php

declare(strict_types=1);

namespace App\Providers;

use App\Otp\Sms\LocalEchoSmsSender;
use App\Otp\Sms\NullSmsSender;
use App\Otp\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            $driver = config('ridemate.sms.driver');

            return match ($driver) {
                // The default, and it throws. RideMate has no production SMS
                // provider, and a sender that silently discarded would produce
                // a deployment where sign-in looks fine and no member can get
                // in. See NullSmsSender.
                'null' => new NullSmsSender,

                // Fails closed in its own constructor if the environment is
                // not local, so a leaked configuration value cannot activate
                // it on a real deployment.
                'local_echo' => new LocalEchoSmsSender,

                // Not a fallback to null: an unrecognised driver is a
                // configuration mistake, and quietly substituting something
                // would hide it until a member complained.
                default => throw new InvalidArgumentException(
                    'Unknown SMS driver. Set RIDEMATE_SMS_DRIVER to a supported value.',
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineRateLimiters();
    }

    /**
     * The coarse per-IP limits, named so routes can reference them.
     *
     * Defined here rather than inline on a route so the budgets sit beside
     * each other and come from configuration. The routes that use them arrive
     * with the contract; the definitions are exercised directly by tests until
     * then.
     *
     * A request with no resolvable IP buckets into one shared buffer rather
     * than escaping the limit entirely. That is deliberately the pessimistic
     * choice: sharing a limit is an inconvenience, having none is a hole.
     */
    private function defineRateLimiters(): void
    {
        $limits = [
            'rm-otp-request' => 'otp_request_per_ip_per_hour',
            'rm-otp-verify' => 'otp_verify_per_ip_per_hour',
            'rm-auth-refresh' => 'refresh_per_ip_per_hour',
        ];

        foreach ($limits as $name => $key) {
            $budget = $this->budget($key);

            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perHour($budget)
                ->by($request->ip() ?? 'unknown'));
        }
    }

    private function budget(string $key): int
    {
        $value = config("ridemate.rate_limits.$key");

        if (! is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException("ridemate.rate_limits.$key is not a usable limit.");
        }

        return (int) $value;
    }
}
