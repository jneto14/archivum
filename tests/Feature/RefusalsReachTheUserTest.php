<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A write refused for a reason that is not about a field has to be seen.
 *
 * Reaching a workspace's document limit produced nothing at all: the request
 * failed, the form stayed put, and no message appeared anywhere. The reason is
 * that a refusal is raised as a validation error, a validation error is
 * addressed to a *field*, and a page renders only the ones it has an input for.
 * `CreateDocument` addressed its message to `workspace`, and no form has a
 * field by that name — so it arrived, was read by nothing, and was dropped.
 *
 * The application already had somewhere to say this: the flash toast that
 * thirty-odd successful writes use, whose type has always allowed `error`.
 * These messages now go there.
 *
 * The existing limit test asserted only that the message was *raised*, which is
 * exactly how this shipped — it passed the whole time the limit was silent.
 */
class RefusalsReachTheUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Flash data is written for the *next* request, and a validation failure
     * redirects back — so this also pins the thing the fix depends on: that the
     * toast survives the redirect the exception causes.
     */
    public function test_reaching_the_document_limit_flashes_an_error_toast()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Existing', null, null);

        WorkspaceLimit::factory()->for($workspace)->create(['documents' => 1]);

        $response = $this->actingAs($member->user)->post(route('documents.store', $workspace), [
            'document_type_id' => $type->id,
            'title' => 'Should not fit',
        ]);

        $response->assertSessionHas('inertia.flash_data', [
            'toast' => ['type' => 'error', 'message' => __('document.limit_reached')],
        ]);

        $this->assertDatabaseMissing('documents', ['title' => 'Should not fit']);
    }

    /**
     * The write still fails the way it did, so the form keeps what was typed
     * and the response is still a validation failure rather than a success
     * carrying a complaint.
     */
    public function test_the_refusal_is_still_a_validation_failure()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Existing', null, null);

        WorkspaceLimit::factory()->for($workspace)->create(['documents' => 1]);

        $this->actingAs($member->user)
            ->post(route('documents.store', $workspace), [
                'document_type_id' => $type->id,
                'title' => 'Should not fit',
            ])
            ->assertSessionHasErrors('workspace');
    }
}
