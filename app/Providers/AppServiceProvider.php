<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Policies\OwnedByUserPolicy;
use App\Services\Receipts\ClaudeReceiptScanner;
use App\Services\Receipts\GeminiReceiptScanner;
use App\Services\Receipts\NullReceiptScanner;
use App\Services\Receipts\ReceiptScanner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\RecurringRule;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReceiptScanner::class, function () {
            $driver = config('receipts.scanner.driver');

            return match (true) {
                $driver === 'gemini' && filled(config('receipts.scanner.gemini.key')) => new GeminiReceiptScanner,
                $driver === 'claude' && filled(config('receipts.scanner.claude.key')) => new ClaudeReceiptScanner,
                default => new NullReceiptScanner,
            };
        });
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

        RateLimiter::for('receipt-scan', fn (Request $request) => Limit::perHour((int) config('receipts.scan_per_hour'))
            ->by($request->user()?->id ?: $request->ip()));

        foreach ([Wallet::class, Category::class, Transaction::class, Budget::class, RecurringRule::class, Goal::class] as $model) {
            Gate::policy($model, OwnedByUserPolicy::class);
        }
    }
}
