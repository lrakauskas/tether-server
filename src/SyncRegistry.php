<?php

namespace Tether\Server;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tether\Core\Conflict\ConflictResolution;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\Snapshot;
use Tether\Server\Support\TetherLog;

class SyncRegistry
{
    /**
     * @var array<class-string<Model>, SyncRegistration>
     */
    private array $registrations = [];

    /**
     * Register a model class for state-based pull sync.
     *
     * @param  class-string<Model>  $modelClass
     * @param  (callable(Builder<Model> $scope, string $clientId, Request $request): Builder<Model>)|null  $scope
     *         Restrict which rows a client may pull.
     * @param  (callable(Snapshot $pullSnapshotMapper, Model $row): Snapshot)|null  $pullSnapshotMapper
     *         Transform or filter a pull snapshot before it is sent to the client.
     * @param  (callable(Mutation $pushMutationMapper, Request $request): Mutation)|null  $pushMutationMapper
     *         Transform or filter an inbound push mutation before conflict detection and application.
     * @param  (callable(Mutation $conflictResolver, Model $serverRecord, Request $request): ConflictResolution)|null  $conflictResolver
     *         Custom conflict resolution strategy for this model. Receives the incoming payload,
     *         the current server record, and the HTTP request. Return ConflictResolution::apply($payload)
     *         to accept the mutation (optionally with a transformed payload), or
     *         ConflictResolution::reject() to keep the server state.
     *         When null, the default last-write-wins strategy is used.
     */
    public function register(
        string $modelClass,
        ?callable $scope = null,
        ?callable $pullSnapshotMapper = null,
        ?callable $pushMutationMapper = null,
        ?callable $conflictResolver = null,
    ): void {
        $this->registrations[$modelClass] = new SyncRegistration(
            scope: $scope,
            pullSnapshotMapper: $pullSnapshotMapper,
            pushMutationMapper: $pushMutationMapper,
            conflictResolver: $conflictResolver,
        );

        TetherLog::debug('Registered sync model', 2, [
            'model_class' => $modelClass,
            'has_scope' => $scope !== null,
            'has_pull_snapshot_mapper' => $pullSnapshotMapper !== null,
            'has_push_mutation_mapper' => $pushMutationMapper !== null,
            'has_conflict_resolver' => $conflictResolver !== null,
        ]);
    }

    /**
     * @return array<class-string<Model>, SyncRegistration>
     */
    public function getRegistrations(): array
    {
        return $this->registrations;
    }

    public function isRegistered(string $modelClass): bool
    {
        return isset($this->registrations[$modelClass]);
    }

    public function getScope(string $modelClass): ?callable
    {
        $scope = $this->registrations[$modelClass]->scope ?? null;

        return is_callable($scope) ? $scope : null;
    }

    public function getPullSnapshotMapper(string $modelClass): ?callable
    {
        $mapper = $this->registrations[$modelClass]->pullSnapshotMapper ?? null;

        return is_callable($mapper) ? $mapper : null;
    }

    public function getPushMutationMapper(string $modelClass): ?callable
    {
        $mapper = $this->registrations[$modelClass]->pushMutationMapper ?? null;

        return is_callable($mapper) ? $mapper : null;
    }

    public function getConflictResolver(string $modelClass): ?callable
    {
        $resolver = $this->registrations[$modelClass]->conflictResolver ?? null;

        return is_callable($resolver) ? $resolver : null;
    }
}
