<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;

class DocumentTypePolicy
{
    /**
     * Determine whether the user may list document types in the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose document types are being listed.
     *
     * @return bool True if $user is a member of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given document type.
     *
     * @param User $user The acting user.
     * @param DocumentType $documentType The document type being viewed.
     *
     * @return bool True if $user is a member of $documentType's workspace.
     */
    public function view(User $user, DocumentType $documentType): bool
    {
        return $documentType->workspace->isMember($user);
    }

    /**
     * Only workspace admins may define new document types.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace the document type would be created in.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may update a document type.
     *
     * @param User $user The acting user.
     * @param DocumentType $documentType The document type being updated.
     *
     * @return bool True if $user is an admin of $documentType's workspace.
     */
    public function update(User $user, DocumentType $documentType): bool
    {
        return $documentType->workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may delete a document type.
     *
     * @param User $user The acting user.
     * @param DocumentType $documentType The document type being deleted.
     *
     * @return bool True if $user is an admin of $documentType's workspace.
     */
    public function delete(User $user, DocumentType $documentType): bool
    {
        return $documentType->workspace->isAdmin($user);
    }
}
