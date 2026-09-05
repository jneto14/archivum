<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Task;
use App\Support\SignedLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the user who triggered a document export that it has finished,
 * linking to a signed, time-limited download of the generated CSV.
 */
class DocumentExportReady extends Notification
{
    /**
     * @param Task $task The completed export task whose result is downloadable.
     */
    public function __construct(
        private readonly Task $task,
    ) {}

    /**
     * Deliver the notification by mail only.
     *
     * @param mixed $notifiable The user who triggered the export.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the export-ready email, linking to a signed download route that
     * expires alongside the export file's retention window.
     *
     * @param mixed $notifiable The user who triggered the export.
     *
     * @return MailMessage A message with a greeting, an intro line, and a "download" call-to-action button.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $retentionDays = config('archivum.attachments.export_retention_days');

        $url = SignedLink::temporary(
            'workspaces.tasks.download.signed',
            now()->addDays($retentionDays),
            ['workspace' => $this->task->workspace_id, 'task' => $this->task->id],
        );

        return (new MailMessage())
            ->subject(__('export.mail_subject'))
            ->greeting(__('export.mail_greeting'))
            ->line(__('export.mail_intro'))
            ->action(__('export.mail_action'), $url)
            ->line(__('export.mail_expires', ['count' => $retentionDays]))
            ->line(__('export.mail_footer'));
    }
}
