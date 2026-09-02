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
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        $this->configureTrustedProxies();
        $this->configureUrls();
        $this->configureDefaults();
        $this->configureWorkspaceMembership();
        $this->configurePasskeys();
        $this->configurePlatformAdminAccess();
        $this->configureActivityLog();
        $this->configureDemoMode();
    }

    /**
     * Decide which proxies may describe the original request.
     *
     * Laravel ships the TrustProxies middleware and trusts nobody by default,
     * so an installation that sets TRUSTED_PROXIES and nothing else gets no
     * effect at all. What that costs, behind a proxy terminating TLS: the
     * session cookie is not marked Secure, and every request appears to come
     * from the proxy's own address, so `throttle:6,1` on the login and password
     * routes counts the whole internet as one client and locks everybody out
     * together. Neither failure raises anything.
     *
     * Configured here rather than in bootstrap/app.php because the middleware
     * closure there runs before the framework loads the environment or the
     * config — an `env()` call in it reads only what the process already had,
     * so it would work in a container and silently do nothing for an
     * installation that keeps the value in `.env`, and nothing at all once the
     * config is cached. Providers boot before any middleware handles a request,
     * so setting the static here is in time.
     *
     * @return void No return value; names the trusted proxies on the middleware as a side effect.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = (string) config('archivum.trusted_proxies');

        if ($proxies === '') {
            return;
        }

        TrustProxies::at(
            $proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)),
        );
    }

    /**
     * Generate every URL from APP_URL rather than from the incoming request.
     *
     * Left to itself Laravel builds URLs from what the request appears to say,
     * which behind a reverse proxy is what the proxy forwarded rather than what
     * the browser asked for. Two things go wrong, and both of them look like
     * the application being broken rather than the deployment being
     * misdescribed:
     *
     * A proxy that terminates TLS forwards plain HTTP, so redirects come back
     * as `http://` and the browser refuses them or downgrades the connection.
     *
     * And an installation served under a path — `https://example.com/archivum`
     * — is reached by a proxy that strips the prefix before forwarding, so the
     * application never sees it. It generates `/login`, which resolves against
     * the wrong root and lands outside the installation entirely.
     *
     * Forcing the root URL fixes both, because APP_URL is the one place that
     * states what the outside world calls this installation. The scheme is
     * forced as well: the root URL settles what generated links look like, but
     * `secure_url()` and the framework's own "is this request secure" checks
     * read the scheme, and those decide things like whether a session cookie is
     * marked Secure.
     *
     * This is not the whole job. `configureTrustedProxies()` above is what
     * makes the forwarded headers believed at all, and the client-side router
     * has a path prefix of its own to learn about — see docs/deployment.md.
     *
     * @return void No return value; fixes the URL generator's root and scheme as a side effect.
     */
    protected function configureUrls(): void
    {
        $url = (string) config('app.url');

        if ($url === '') {
            return;
        }

        URL::forceRootUrl($url);

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (is_string($scheme)) {
            URL::forceScheme($scheme);
        }
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
