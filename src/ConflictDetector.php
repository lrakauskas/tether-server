<?php

namespace Tether\Server;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tether\Core\Conflict\ConflictResolution;
use Tether\Core\Mutation\Mutation;
use Tether\Server\Support\TetherLog;

/**
 * Detects and resolves conflicts between an incoming client mutation and the
 * current server state for the affected entity.
 *
 * Default strategy: last-write-wins (record level).
 *   - Mutation timestamp (Unix millisecond integer) is compared to the server entity's updated_at.
 *   - If the mutation is equal to or newer than the server state → no conflict.
 *   - If the mutation is older → conflict. Custom resolver (if any) decides the outcome;
 *     the built-in default rejects the incoming mutation (server state wins).
 *
 * A custom resolver can be registered per model via SyncRegistry::register(..., conflictResolver: ...).
 */
class ConflictDetector
{
    public function __construct(
        private readonly SyncRegistry $registry,
    ) {}

    /**
     * Evaluate an incoming mutation against the current server entity state.
     *
     * Returns null when there is no conflict (mutation should be applied as normal).
     * Returns a ConflictResolution when a conflict is detected - the caller must
     * honour the resolution decision.
     *
     * @param  string                $modelClass    Fully-qualified server model class
     * @param  Model|null            $serverRecord  The current server record (null when creating)
     * @return ConflictResolution|null              Null = no conflict; otherwise: resolution decision
     */
    public function evaluate(
        Mutation $mutation,
        string $modelClass,
        ?Model $serverRecord,
        Request $request,
    ): ?ConflictResolution {
        // Creates can never conflict - the entity doesn't exist on the server yet.
        if ($serverRecord === null) {
            return null;
        }

        $mutationTimestamp = $mutation->getTimestamp();
        $serverUpdatedAt = $serverRecord->getAttribute('updated_at')
            ? (int) $serverRecord->getAttribute('updated_at')->getPreciseTimestamp(3)
            : 0;

        // If the mutation timestamp is equal to or later than the server's last update,
        // the client's write is authoritative - no conflict.
        if ($mutationTimestamp >= $serverUpdatedAt) {
            return null;
        }

        TetherLog::debug('Conflict detected for push mutation', 2, [
            'mutation_id' => $mutation->getMutationId(),
            'model_class' => $modelClass,
            'entity_id' => $mutation->getEntityId(),
            'mutation_timestamp' => $mutationTimestamp,
            'server_updated_at' => $serverUpdatedAt,
        ]);

        // Conflict: the server record was updated more recently than this mutation.
        // Delegate to a custom resolver if registered for this model; otherwise use LWW default.
        $resolver = $this->registry->getConflictResolver($modelClass);

        if ($resolver !== null) {
            TetherLog::debug('Running custom conflict resolver', 3, [
                'mutation_id' => $mutation->getMutationId(),
                'model_class' => $modelClass,
            ]);

            return ($resolver)(
                $mutation,
                $serverRecord,
                $request,
            );
        }

        // Default LWW: server wins - reject the incoming mutation.
        return ConflictResolution::reject();
    }
}
