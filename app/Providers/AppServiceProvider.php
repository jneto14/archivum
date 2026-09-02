<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Enums\WorkspaceRole;
use App\Models\Passkey;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Policies\ActivityPolicy;
use App\Services\Ocr\Contracts\OcrEngine;
use App\Services\Ocr\TesseractEngine;
use App\Support\DemoMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void No return value; binds the workspace usage calculator and the OCR engine.
     */
    public function register(): void
    {
        // Scoped, not transient: CalculateWorkspaceUsage memoises its totals for
        // the request, and a fresh instance per injection would defeat that.
        $this->app->scoped(CalculateWorkspaceUsage::class);

        // The one place that picks which OCR engine the application runs on.
        // Everything else depends on the OcrEngine contract, so a hosted engine
        // could be selected here from config without touching the pipeline —
        // and tests swap in a fake, which is why they need no tesseract binary.
        $this->app->bind(OcrEngine::class, fn (): OcrEngine => new TesseractEngine(
            (string) config('archivum.ocr.languages'),
            (int) config('archivum.ocr.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void No return value; delegates to the configureX() methods below.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWorkspaceMembership();
        $this->configurePasskeys();
        $this->configurePlatformAdminAccess();
        $this->configureActivityLog();
        $this->configureDemoMode();
    }

    /**
     * Apply the restrictions a public demo needs.
     *
     * Mail is redirected at the transport rather than at each sender, because
     * the failure to avoid is a demo quietly emailing a stranger: workspace
     * invitations and export links both go out on their own, and a per-feature
     * switch is one someone will forget to add to the next feature that sends
     * something. Nothing is sent from a demo, whatever asks.
     *
     * This also disables password reset in practice — the mail carrying the
     * link is never delivered — which is the intended outcome. Changing a
     * password from inside the application is blocked separately, by
     * DenyInDemoMode on the route.
     *
     * @return void No return value; overrides the mail transport as a side effect.
     */
    protected function configureDemoMode(): void
    {
        if (!DemoMode::enabled()) {
            return;
        }

        config(['mail.default' => 'log']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     *
     * @return void No return value; sets global date/DB/password defaults as a side effect.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised());
    }

    /**
     * In single-workspace mode, every newly registered user is automatically
     * attached to the sole default workspace — otherwise they'd have no
     * membership and would be locked out of any workspace-scoped route.
     *
     * @return void No return value; registers a User::created listener as a side effect.
     */
    protected function configureWorkspaceMembership(): void
    {
        User::created(function (User $user): void {
            if (config('archivum.multi_workspace_enabled')) {
                return;
            }

            $workspace = Workspace::query()->first();

            if ($workspace === null || $workspace->isMember($user)) {
                return;
            }

            WorkspaceUser::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => WorkspaceRole::User,
            ]);
        });
    }

    /**
     * Use this app's UUID-keyed Passkey model instead of the package's
     * default, which assumes an auto-incrementing integer primary key.
     */
    protected function configurePasskeys(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);
    }

    /**
     * Platform admins bypass every workspace-scoped Policy check regardless of
     * their own membership, rather than duplicating an `is_platform_admin`
     * check inside each Policy method.
     *
     * @return void No return value; registers a Gate::before callback as a side effect.
     */
    protected function configurePlatformAdminAccess(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => $user->is_platform_admin ? true : null);
    }

    /**
     * Spatie's Activity model lives outside App\Models, so Laravel's naming-convention
     * policy discovery can't find ActivityPolicy on its own — it must be registered explicitly.
     *
     * @return void No return value; registers a Gate::policy() mapping as a side effect.
     */
    protected function configureActivityLog(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
