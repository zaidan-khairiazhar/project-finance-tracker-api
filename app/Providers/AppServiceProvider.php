<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Policies\OwnedByUserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Budget;
use App\Models\RecurringRule;

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
        foreach ([Wallet::class, Category::class, Transaction::class] as $model) {
            Gate::policy($model, OwnedByUserPolicy::class);
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        foreach ([Wallet::class, Category::class, Transaction::class, Budget::class, RecurringRule::class] as $model) {
            Gate::policy($model, OwnedByUserPolicy::class);
        }
    }
}
