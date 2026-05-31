<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tether\Core\Conflict\ConflictResolution;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\Snapshot;
use Tether\Server\Traits\Syncable;

class TestSyncablePost extends Model
{
    use Syncable;

    protected $table = 'test_posts';

    protected $guarded = [];

    public static function tetherScope(Builder $query, string $clientId, Request $request): Builder
    {
        return $query;
    }

    public static function tetherPullSnapshotMapper(Snapshot $snapshot, Model $row): Snapshot
    {
        return $snapshot->withPayload(array_merge($snapshot->payload, [
            'mapped_pull' => true,
        ]));
    }

    public static function tetherPushMutationMapper(Mutation $mutation, Request $request): Mutation
    {
        return $mutation->withPayload(array_merge($mutation->getPayload(), [
            'mapped_push' => true,
        ]));
    }

    public static function tetherConflictResolver(Mutation $mutation, Model $record, Request $request): ConflictResolution
    {
        return ConflictResolution::reject();
    }
}
