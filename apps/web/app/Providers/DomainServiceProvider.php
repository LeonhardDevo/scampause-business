<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\UsageResolver;
use App\Domain\Billing\ApprovalRulesUsage;
use App\Domain\Billing\PaymentRequestsMonthlyUsage;
use App\Domain\Billing\VendorsUsage;
use App\Domain\Intake\Console\DemoWorkspaceCommand;
use App\Domain\Intake\Console\PipelineSweepCommand;
use App\Domain\Intake\Contracts\PolicyPreview;
use App\Domain\Intake\Files\FileCipher;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\BaseCurrencyGuard;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Policy\RequestPolicy;
use App\Domain\Reports\Contracts\DashboardData;
use App\Domain\Reports\DomainDashboard;
use App\Domain\Risk\Contracts\PolicyVerificationResolver;
use App\Domain\Support\Clock;
use App\Domain\Support\Fingerprinter;
use App\Listeners\Domain\DomainEventSubscriber;
use App\Listeners\Domain\HandleDomainDeletions;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Policies\Domain\PaymentRequestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(ClockInterface::class, Clock::class);
        $this->app->singleton(FileCipher::class, static fn (): FileCipher => FileCipher::fromConfig());
        $this->app->bind(Fingerprinter::class, static fn (): Fingerprinter => Fingerprinter::fromConfig());
        $this->app->bind(PolicyVerificationResolver::class, RequestPolicy::class);
        $this->app->bind(PolicyPreview::class, RequestPolicy::class);
        $this->app->bind(DashboardData::class, DomainDashboard::class);
        $this->app->singleton(PaymentRequestsMonthlyUsage::class);
        $this->app->singleton(VendorsUsage::class);
        $this->app->singleton(ApprovalRulesUsage::class);
        $this->app->tag([PaymentRequestsMonthlyUsage::class, VendorsUsage::class, ApprovalRulesUsage::class], UsageResolver::TAG);
    }

    public function boot(): void
    {
        $this->guardProduction();

        Gate::policy(PaymentRequest::class, PaymentRequestPolicy::class);
        Event::subscribe(HandleDomainDeletions::class);
        Event::subscribe(DomainEventSubscriber::class);

        AuditLog::created(static function (AuditLog $log): void {
            app(DomainEventSubscriber::class)->auditRecorded($log);
        });

        Workspace::created(static function (Workspace $workspace): void {
            PaymentSettings::ensure($workspace);
        });

        Workspace::updating(static function (Workspace $workspace): void {
            BaseCurrencyGuard::check($workspace);
        });

        RateLimiter::for('sp-uploads', static fn (Request $request): Limit => Limit::perMinute(20)
            ->by('sp-uploads|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('sp-reveal', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('sp-reveal|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->loadRoutesFrom(base_path('routes/guest-domain.php'));

        if (self::devRoutesEnabled($this->app->isProduction(), (bool) config('scampause.dev_routes'))) {
            $this->loadRoutesFrom(base_path('routes/dev-domain.php'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PipelineSweepCommand::class, DemoWorkspaceCommand::class]);
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command(PipelineSweepCommand::class)->everyFiveMinutes()->withoutOverlapping();
        });
    }

    public static function devRoutesEnabled(bool $production, bool $flag): bool
    {
        return ! $production && $flag;
    }

    public function guardProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $problems = [];

        if (config('scampause.domain_intel_driver') !== 'live') {
            $problems[] = 'DOMAIN_INTEL_DRIVER must be live in production';
        }

        if (! is_string(config('scampause.fingerprint_key')) || config('scampause.fingerprint_key') === '') {
            $problems[] = 'SCAMPAUSE_FINGERPRINT_KEY is required in production';
        }

        if (! is_string(config('scampause.file_key')) || config('scampause.file_key') === '') {
            $problems[] = 'SCAMPAUSE_FILE_KEY is required in production';
        }

        if ($problems !== []) {
            throw new \RuntimeException("Insecure production configuration:\n  - ".implode("\n  - ", $problems));
        }
    }
}
