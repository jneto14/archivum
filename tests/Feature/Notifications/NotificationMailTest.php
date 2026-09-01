<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders the two mails that carry a link someone has to click.
 *
 * Asserting only that a notification was *sent* — which is what the rest of the
 * suite does — never runs `toMail()`, so a broken route name, a missing
 * translation key or a token left out of the URL would ship silently and only
 * surface as a dead link in someone's inbox.
 */
class NotificationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_reset_mail_links_to_the_reset_route_with_the_token_and_email()
    {
        $user = User::factory()->create(['email' => 'someone@example.test']);

        $mail = (new ResetPassword('the-token'))->toMail($user);

        $this->assertNotEmpty($mail->subject);
        $this->assertSame(
            url(route('password.reset', ['token' => 'the-token', 'email' => 'someone@example.test'], false)),
            $mail->actionUrl,
        );
        $this->assertStringNotContainsString('passwords.', (string) $mail->subject);
    }

    public function test_the_invitation_mail_links_to_the_accept_route_and_names_the_workspace()
    {
        $user = User::factory()->create(['email' => 'invitee@example.test']);

        $mail = (new WorkspaceInvitation('the-token', 'Studio Archive'))->toMail($user);

        $this->assertStringContainsString('Studio Archive', (string) $mail->subject);
        $this->assertSame(
            url(route('invitations.accept', ['token' => 'the-token', 'email' => 'invitee@example.test'], false)),
            $mail->actionUrl,
        );
    }

    public function test_both_mails_resolve_every_translation_key()
    {
        $user = User::factory()->create();

        $messages = [
            (new ResetPassword('t'))->toMail($user),
            (new WorkspaceInvitation('t', 'Studio Archive'))->toMail($user),
        ];

        foreach ($messages as $mail) {
            $rendered = array_merge(
                [(string) $mail->subject, (string) $mail->actionText],
                array_map(strval(...), $mail->introLines),
                array_map(strval(...), $mail->outroLines),
            );

            foreach ($rendered as $line) {
                $this->assertNotSame('', $line);

                // An unresolved key renders as the key itself, dots and all.
                $this->assertDoesNotMatchRegularExpression(
                    '/^(passwords|invitation)\.[a-z_]+$/',
                    $line,
                    "The mail rendered [{$line}], which is a translation key rather than a translation.",
                );
            }
        }
    }
}
