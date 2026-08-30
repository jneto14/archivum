<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Replaces Laravel's built-in Illuminate\Auth\Notifications\ResetPassword,
 * whose mail copy is only translatable via a lang/{locale}.json file this
 * project doesn't have — so it always rendered in English regardless of
 * the user's locale. This version reuses lang/*\/passwords.php instead,
 * which Laravel's HasLocalePreference-driven locale switch already applies.
 */
class ResetPassword extends Notification
{
    /**
     * @param string $token The password reset token embedded in the reset-password link.
     */
    public function __construct(
        #[SensitiveParameter] public readonly string $token,
    ) {}

    /**
     * Deliver the notification by mail only.
     *
     * @param mixed $notifiable The user requesting a password reset.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the reset-password email, linking to the reset-password route
     * and noting the token's expiry.
     *
     * @param mixed $notifiable The user requesting a password reset; must resolve an email
     *                          via `getEmailForPasswordReset()`, embedded in the reset link.
     *
     * @return MailMessage A message with an intro line, a "reset password" call-to-action
     *                     button linking to the signed route, the token's expiry, and a footer line.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage())
            ->subject(__('passwords.mail_subject'))
            ->line(__('passwords.mail_intro'))
            ->action(__('passwords.mail_action'), $url)
            ->line(__('passwords.mail_expires', ['count' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire')]))
            ->line(__('passwords.mail_footer'));
    }
}
