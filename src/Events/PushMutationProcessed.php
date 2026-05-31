<?php

namespace Tether\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tether\Core\Mutation\Mutation;

final readonly class PushMutationProcessed
{
    use Dispatchable;

    /**
     * @param array<string, mixed>|null $serverStateBefore
     * @param array<string, mixed>|null $serverStateAfter
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $syncId,
        public string $clientId,
        public Mutation $mutation,
        public string $outcome,
        public Request $httpRequest,
        public ?Mutation $processedMutation = null,
        public ?string $reason = null,
        public ?array $serverStateBefore = null,
        public ?array $serverStateAfter = null,
        public array $context = [],
        public float $durationMs = 0.0,
    ) {}
}
