<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
}
