<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\WorkspaceRole;
use App\Models\Passkey;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void No services are registered here.
     */
    public function register(): void
    {
        //
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
}
