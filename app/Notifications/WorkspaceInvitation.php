<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites a user to join a workspace via a signed, time-limited token
 * emailed to them.
 */
class WorkspaceInvitation extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        private readonly string $workspaceName,
    ) {}

    /**
     * Deliver the invitation by mail only.
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
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url(route('invitations.accept', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject(__('invitation.mail_subject', ['workspace' => $this->workspaceName]))
            ->greeting(__('invitation.mail_greeting', ['workspace' => $this->workspaceName]))
            ->line(__('invitation.mail_intro'))
            ->action(__('invitation.mail_action'), $url)
            ->line(__('invitation.mail_expires', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('invitation.mail_footer'));
    }
}
