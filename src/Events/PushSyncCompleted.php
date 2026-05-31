<?php

namespace Tether\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tether\Core\Sync\PushRequest;
use Tether\Core\Sync\PushResult;

final readonly class PushSyncCompleted
{
    use Dispatchable;

    public function __construct(
        public string $syncId,
        public PushRequest $pushRequest,
        public PushResult $result,
        public Request $httpRequest,
        public float $startedAt,
        public float $durationMs,
    ) {}
}
