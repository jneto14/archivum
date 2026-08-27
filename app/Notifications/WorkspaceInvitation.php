<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Invites a user to join a workspace via a signed, time-limited token
 * emailed to them.
 */
class WorkspaceInvitation extends Notification
{
    /**
     * @param string $token The signed, single-use invitation token embedded in the accept-invitation link.
     * @param string $workspaceName The workspace's display name, interpolated into the mail copy.
     */
    public function __construct(
        #[SensitiveParameter] public readonly string $token,
        private readonly string $workspaceName,
    ) {}

    /**
     * Deliver the invitation by mail only.
     *
     * @param mixed $notifiable The invitee. Not a persisted User (they may not have an account yet) —
     *                          typically an on-the-fly `Illuminate\Notifications\AnonymousNotifiable`.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the invitation email, linking to the accept-invitation route
     * and noting the token's expiry.
     *
     * @param mixed $notifiable The invitee; must resolve an email via `getEmailForPasswordReset()`,
     *                          which is embedded in the accept link alongside the token.
     *
     * @return MailMessage A message with a greeting, an intro line, an "accept invitation" call-to-action
     *                     button linking to the signed route, the token's expiry, and a footer line.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url(route('invitations.accept', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage())
            ->subject(__('invitation.mail_subject', ['workspace' => $this->workspaceName]))
            ->greeting(__('invitation.mail_greeting', ['workspace' => $this->workspaceName]))
            ->line(__('invitation.mail_intro'))
            ->action(__('invitation.mail_action'), $url)
            ->line(__('invitation.mail_expires', ['count' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire')]))
            ->line(__('invitation.mail_footer'));
    }
}
