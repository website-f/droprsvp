<?php

namespace App\Providers;

use App\Services\Payments\ChipGateway;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Payment driver: real CHIP when configured, the fake gateway otherwise.
        $this->app->bind(PaymentGateway::class, fn () => config('services.chip.driver') === 'chip'
            ? new ChipGateway()
            : new FakePaymentGateway());

        // Request-scoped server-side SEO (rendered into the <head> by Laravel).
        $this->app->scoped(\App\Support\SeoManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters for abuse-prone endpoints (OTP emails, checkout,
     * public posting, uploads). Applied via `throttle:<name>` in routes/web.php.
     * Authenticated hits key by user id; guests key by IP (OTP also by email).
     */
    protected function configureRateLimiting(): void
    {
        $byUserOrIp = fn (Request $r) => (string) ($r->user()?->id ?: $r->ip());

        // Verification-code emails: strict, keyed by email + IP so one address
        // (or one host) can't be spammed with codes.
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinutes(60, 20)->by($r->ip()),
        ]);

        // Password-reset link requests.
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));

        // Checkout start + pay.
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(30)->by($byUserOrIp($r)));

        // User-generated content: comments, reviews, discussion posts, contact form.
        RateLimiter::for('posting', fn (Request $r) => Limit::perMinute(20)->by($byUserOrIp($r)));

        // File uploads (event covers, CMS media).
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(40)->by($byUserOrIp($r)));

        // Search autocomplete (public, high-frequency but read-only).
        RateLimiter::for('suggest', fn (Request $r) => Limit::perMinute(120)->by($byUserOrIp($r)));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
