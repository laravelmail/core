<?php

declare(strict_types=1);

namespace LaravelMail\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use LaravelMail\Contracts\WorkspaceResolver;

/**
 * Prefixed table name + automatic workspace scoping via the host's WorkspaceResolver.
 */
trait BelongsToWorkspace
{
    public function getTable()
    {
        return config('laravelmail.table_prefix', 'laravelmail_').$this->baseTable;
    }

    protected static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('laravelmail_workspace', function (Builder $q) {
            $id = app(WorkspaceResolver::class)->currentId();
            if ($id !== null) {
                $q->where($q->getModel()->getTable().'.workspace_id', (string) $id);
            }
        });

        static::creating(function ($model) {
            if ($model->workspace_id === null) {
                $id = app(WorkspaceResolver::class)->currentId();
                $model->workspace_id = $id === null ? null : (string) $id;
            }
        });
    }
}
