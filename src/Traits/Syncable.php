<?php

namespace Tether\Server\Traits;

use Tether\Server\SyncRegistry;

/**
 * Apply this trait to any server-side Eloquent model that should be
 * served to clients via Tether pull sync.
 *
 * Self-registers the model with SyncRegistry during application boot.
 * For fine-grained control (scope, pull snapshot mapper, etc.) you can still
 * call SyncRegistry::register() explicitly in a service provider - that
 * call will override the trait registration.
 *
 * Usage - minimal (all rows, all public columns):
 *
 *   class Post extends Model
 *   {
 *       use Syncable;
 *   }
 *
 * Usage - with scope and mappers:
 *
 *   class Post extends Model
 *   {
 *       use Syncable;
 *
 *       // Only allow these fields to be written by incoming client mutations.
 *       // If omitted, all fields in $fillable are accepted (same as $fillable behaviour).
 *       protected array $syncable = ['title', 'body', 'status'];
 *
 *       // Or: accept all fillable fields except these.
 *       protected array $syncableExcept = ['internal_notes', 'admin_flag'];
 *
 *       // Restrict rows sent to requesting client (e.g. user-owned records)
 *       public static function tetherScope(Builder $query, string $clientId, Request $request): Builder
 *       {
 *           return $query->where('user_id', $clientId);
 *       }
 *
 *       // Strip sensitive fields from outbound pull snapshots
 *       public static function tetherPullSnapshotMapper(Snapshot $snapshot, Model $row): Snapshot
 *       {
 *           return $snapshot->withPayload(Arr::except($snapshot->payload, ['secret_token']));
 *       }
 *
 *       // Transform inbound push mutation payload before conflict detection and DB writes
 *       public static function tetherPushMutationMapper(Mutation $mutation, Request $request): Mutation
 *       {
 *           return $mutation;
 *       }
 *
 *       // Custom conflict resolver - return ConflictResolution::apply($payload) or ::reject()
 *       public static function tetherConflictResolver(Mutation $mutation, Model $record, Request $request): ConflictResolution
 *       {
 *           return ConflictResolution::apply($mutation->getPayload()); // client always wins
 *       }
 *   }
 */
trait Syncable
{
    public static function bootSyncable(): void
    {
        app()->booted(function () {
            /** @var SyncRegistry $registry */
            $registry = app(SyncRegistry::class);

            $modelClass = static::class;

            // Build optional callbacks from well-known static methods on the model.
            $scope = method_exists($modelClass, 'tetherScope')
                ? [static::class, 'tetherScope']
                : null;

            $pullSnapshotMapper = method_exists($modelClass, 'tetherPullSnapshotMapper')
                ? [static::class, 'tetherPullSnapshotMapper']
                : null;

            $pushMutationMapper = method_exists($modelClass, 'tetherPushMutationMapper')
                ? [static::class, 'tetherPushMutationMapper']
                : null;

            $conflictResolver = method_exists($modelClass, 'tetherConflictResolver')
                ? [static::class, 'tetherConflictResolver']
                : null;

            // Only register if not already explicitly registered via a service provider.
            if (! $registry->isRegistered($modelClass)) {
                $registry->register(
                    modelClass: $modelClass,
                    scope: $scope,
                    pullSnapshotMapper: $pullSnapshotMapper,
                    pushMutationMapper: $pushMutationMapper,
                    conflictResolver: $conflictResolver,
                );
            }
        });
    }

    /**
     * Returns the list of field names that incoming mutation payloads are filtered to
     * before being written to the database.
     *
     * Resolution order:
     *   1. $syncable = ['field', ...]  - explicit whitelist
     *   2. $syncableExcept = ['field', ...] - all fillable except listed
     *   3. No properties set - no filtering, full payload is passed to fill()
     *
     * @return string[]|null  null means "no filtering - accept everything"
     */
    public function getSyncableFields(): ?array
    {
        if (property_exists($this, 'syncable') && $this->syncable !== ['*']) {
            return $this->syncable;
        }

        $fillable = $this->getFillable();

        if (empty($fillable)) {
            return null;
        }

        $except = property_exists($this, 'syncableExcept') ? $this->syncableExcept : [];

        if (! empty($except)) {
            return array_values(array_diff($fillable, $except));
        }

        // $syncable = ['*'] or nothing set - no filtering
        return null;
    }
}
