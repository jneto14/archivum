<?php

declare(strict_types=1);

namespace App\Concerns;

use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Wraps Spatie's LogsActivity trait with Archivum's workspace scoping:
 * every activity_log row gets stamped with the workspace it belongs to
 * (via the model-specific `resolveActivityWorkspaceId()`) and a short
 * human label (via `resolveActivityLabel()`), so the Activity page can
 * filter strictly per workspace and render a readable feed without
 * needing to know each subject type's display fields.
 */
trait LogsWorkspaceActivity
{
    use LogsActivity;

    /**
     * Hook called by Spatie\Activitylog's LogActivityAction right before an
     * activity is persisted, since this model implements it (see
     * LogActivityAction::beforeActivityLogged()).
     *
     * @param Activity $activity The activity about to be persisted.
     * @param string $eventName The lifecycle event that triggered logging ('created', 'updated', 'deleted', ...).
     *
     * @return void No return value; sets `workspace_id` and a `label` property on $activity as a side effect.
     */
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->workspace_id = $this->resolveActivityWorkspaceId();
        $activity->properties = $activity->properties->put('label', $this->resolveActivityLabel());
    }

    /**
     * @return string|null The id of the workspace this model belongs to, for activity-log scoping.
     */
    abstract protected function resolveActivityWorkspaceId(): ?string;

    /**
     * @return string|null A short human-readable label identifying this specific record in the activity feed.
     */
    abstract protected function resolveActivityLabel(): ?string;
}
