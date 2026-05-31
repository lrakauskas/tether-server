<?php

namespace Tether\Server;

final readonly class SyncRegistration
{
    /**
     * @param callable|null $scope
     * @param callable|null $pullSnapshotMapper
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
