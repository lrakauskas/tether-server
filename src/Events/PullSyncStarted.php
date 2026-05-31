<?php

namespace Tether\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tether\Core\Sync\PullRequest;

final readonly class PullSyncStarted
{
    use Dispatchable;

    public function __construct(
        public string $syncId,
        public PullRequest $pullRequest,
        public Request $httpRequest,
        public float $startedAt,
    ) {}
}
