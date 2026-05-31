<?php

namespace Tether\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tether\Core\Sync\PullRequest;
use Tether\Core\Sync\PullResult;

final readonly class PullSyncCompleted
{
    use Dispatchable;

    public function __construct(
        public string $syncId,
        public PullRequest $pullRequest,
        public PullResult $result,
        public Request $httpRequest,
        public float $startedAt,
        public float $durationMs,
    ) {}
}
