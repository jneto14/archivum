<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The two locks that stand between a real archive and a nightly wipe.
 *
 * `demo:reset` destroys every record and every uploaded file. The realistic
 * accident is not someone typing `DEMO_MODE=true` by mistake — it is a working
 * `.env` copied from the demo onto a customer's installation, because starting
 * from a file that is known to work is what people do. A single boolean
 * survives that copy intact.
 *
 * So the reset also requires `DEMO_RESET_CONFIRM` to repeat the installation's
 * own `APP_URL`. That value cannot travel: the moment the URL changes it stops
 * matching, and making it match again means deliberately retyping the new
 * host's address. It is no defence against someone who means to wipe the data;
 * it is a defence against the distracted copy, which is what actually happens.
 */
final class DemoMode
{
    /**
     * Whether this installation is a demo.
     *
     * Governs the interface — the banner, the offered credentials — and the
     * restrictions, but on its own it is never enough to destroy anything.
     */
    public static function enabled(): bool
    {
        return (bool) config('archivum.demo.enabled');
    }

    /**
     * Why the reset must not run, or null when both locks are satisfied.
     *
     * Returns a reason rather than a boolean so the command can say which lock
     * stopped it. A reset that refuses without explaining sends whoever is
     * holding the terminal looking for a bug that is not there.
     *
     * @return string|null The failing lock, phrased for an operator, or null when the reset may proceed.
     */
    public static function resetBlockedReason(): ?string
    {
        if (!self::enabled()) {
            return 'DEMO_MODE is not enabled on this installation.';
        }

        $confirm = config('archivum.demo.reset_confirm');

        if (blank($confirm)) {
            return 'DEMO_RESET_CONFIRM is not set. It must repeat this installation\'s APP_URL.';
        }

        $appUrl = (string) config('app.url');

        if (self::normalizeUrl((string) $confirm) !== self::normalizeUrl($appUrl)) {
            return sprintf(
                'DEMO_RESET_CONFIRM does not match APP_URL (%s). A value copied from another installation will not match.',
                $appUrl,
            );
        }

        return null;
    }

    /**
     * When the next scheduled reset falls due, for the banner.
     *
     * The configured time is a wall-clock HH:MM in the application timezone,
     * the same string the scheduler is given, so the banner and the schedule
     * cannot drift apart. A time already past today belongs to tomorrow.
     *
     * @return Carbon The next occurrence of the configured reset time.
     */
    public static function nextResetAt(): Carbon
    {
        [$hour, $minute] = array_pad(
            array_map('intval', explode(':', (string) config('archivum.demo.reset_at'), 2)),
            2,
            0,
        );

        $next = Carbon::now()->setTime($hour, $minute);

        return $next->isFuture() ? $next : $next->addDay();
    }

    /**
     * Compare URLs without letting a trailing slash or casing decide whether
     * a wipe happens. `https://demo.example/` and `https://Demo.example` are
     * the same installation; treating them as different would fail the lock
     * for the wrong reason and teach whoever hits it to stop trusting it.
     */
    private static function normalizeUrl(string $url): string
    {
        return mb_strtolower(mb_rtrim(mb_trim($url), '/'));
    }
}
