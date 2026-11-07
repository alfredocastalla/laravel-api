<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('barangay-login', function (Request $request): Limit {
            $username = $request->input('username');
            $username = is_string($username) ? mb_strtolower(trim($username)) : '';
            $key = hash('sha256', $username.'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
    }
}
