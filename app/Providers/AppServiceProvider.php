<?php

declare(strict_types=1);

namespace App\Providers;

use App\Otp\Sms\LocalEchoSmsSender;
use App\Otp\Sms\NullSmsSender;
use App\Otp\Sms\SmsSender;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
        //
    }
}
