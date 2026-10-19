<?php

namespace Tether\Server;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tether\Core\Sync\Snapshot;

final readonly class SyncRegistration
{
    /**
     * @param (callable(Builder<Model>, string, Request): Builder<Model>)|null $scope
     * @param (callable(Snapshot, Model): Snapshot)|null $pullSnapshotMapper
     * @param callable|null $pushMutationMapper
     * @param callable|null $conflictResolver
     */
    public function __construct(
        public mixed $scope = null,
        public mixed $pullSnapshotMapper = null,
        public mixed $pushMutationMapper = null,
        public mixed $conflictResolver = null,
    ) {}
}
