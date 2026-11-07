<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Policies\OwnedByUserPolicy;
use Illuminate\Support\Facades\Gate;

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
    }
}
