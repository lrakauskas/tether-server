<?php

namespace Tether\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tether\Core\Sync\PushRequest;

final readonly class PushSyncStarted
{
    use Dispatchable;

    public function __construct(
        public string $syncId,
        public PushRequest $pushRequest,
        public Request $httpRequest,
        public float $startedAt,
    ) {}
}
