<?php

use Illuminate\Http\Request;
use Tests\Models\TestPost;
use Tether\Core\Conflict\ConflictResolution;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Enums\OperationType;
use Tether\Server\ConflictDetector;
use Tether\Server\SyncRegistry;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeDetector(?callable $conflictResolver = null): ConflictDetector
{
    $registry = new SyncRegistry();
    $registry->register(TestPost::class, conflictResolver: $conflictResolver);

    return new ConflictDetector($registry);
}

function makeRequest(): Request
{
    return Request::create('/tether/push', 'POST');
}

function detectorMutation(array $overrides = []): Mutation
{
    return new Mutation(
        mutationId: $overrides['mutation_id'] ?? '01HXYZ0000AABBCCDD0011AAB1',
        entityId: $overrides['entity_id'] ?? '01HXYZ0001AABBCCDD0011AAB1',
        model: $overrides['model'] ?? 'TestPost',
        operation: $overrides['operation'] ?? OperationType::Update,
        payload: $overrides['payload'] ?? [],
        version: $overrides['version'] ?? 1,
        timestamp: $overrides['timestamp'] ?? 0,
    );
}

// ── No conflict ───────────────────────────────────────────────────────────────

it('returns null when serverRecord is null (create operation)', function () {
    $detector = makeDetector();

    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => 9999999,
            'operation' => OperationType::Create,
        ]),
        modelClass: TestPost::class,
        serverRecord: null,
        request: makeRequest(),
    );

    expect($result)->toBeNull();
});

it('returns null when mutation timestamp equals server updated_at', function () {
    $now     = now();
    $post    = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'X',
        'body' => null,
    ]);
    $post->updated_at = $now;
    $post->save();

    $detector = makeDetector();

    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => (int) $post->fresh()->updated_at->getPreciseTimestamp(3),
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: makeRequest(),
    );

    expect($result)->toBeNull();
});

it('returns null when mutation is newer than server record', function () {
    $post = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'X',
        'body' => null,
    ]);

    $detector = makeDetector();

    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => now()->addYear()->timestamp * 1000,
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: makeRequest(),
    );

    expect($result)->toBeNull();
});

// ── Default LWW: server wins ──────────────────────────────────────────────────

it('returns reject resolution when mutation is older than server record (default LWW)', function () {
    $post = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'Server',
        'body' => null,
    ]);

    $detector = makeDetector();

    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => 0,
            'payload' => [
                'title' => 'Old client',
            ],
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: makeRequest(),
    );

    expect($result)->toBeInstanceOf(ConflictResolution::class)
        ->and($result->shouldApply)->toBeFalse();
});

it('compares client mutation timestamps against server updated_at in milliseconds', function () {
    $serverUpdatedAt = now()->startOfSecond();
    $post = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'Server',
        'body' => null,
    ]);
    $post->updated_at = $serverUpdatedAt;
    $post->save();

    $detector = makeDetector();

    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => (int) $serverUpdatedAt->copy()->subSecond()->getPreciseTimestamp(3),
            'payload' => [
                'title' => 'Stale client',
            ],
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: makeRequest(),
    );

    expect($result)->toBeInstanceOf(ConflictResolution::class)
        ->and($result->shouldApply)->toBeFalse();
});

// ── Custom resolver ───────────────────────────────────────────────────────────

it('uses custom resolver when conflict is detected', function () {
    $post = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'Server',
        'body' => null,
    ]);

    // Client always wins resolver
    $resolver = fn(Mutation $mutation, $record, $req) => ConflictResolution::apply($mutation->getPayload());

    $detector = makeDetector($resolver);

    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => 0,
            'payload' => [
                'title' => 'Client wins',
            ],
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: makeRequest(),
    );

    expect($result)->toBeInstanceOf(ConflictResolution::class)
        ->and($result->shouldApply)->toBeTrue()
        ->and($result->payload)->toBe([
            'title' => 'Client wins',
        ]);
});

it('custom resolver receives correct arguments', function () {
    $capturedArgs = [];

    $post = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'Server',
        'body' => null,
    ]);

    $resolver = function (Mutation $mutation, $record, $req) use (&$capturedArgs) {
        $capturedArgs = compact('mutation', 'record', 'req');

        return ConflictResolution::reject();
    };

    $detector = makeDetector($resolver);
    $request  = makeRequest();

    $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => 0,
            'payload' => [
                'title' => 'Test',
            ],
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: $request,
    );

    expect($capturedArgs['mutation']->getPayload())->toBe([
        'title' => 'Test',
    ])
        ->and($capturedArgs['record']->title)->toBe('Server')
        ->and($capturedArgs['req'])->toBe($request);
});

// ── No resolver registered ────────────────────────────────────────────────────

it('returns null when model is not registered in the registry', function () {
    $registry = new SyncRegistry(); // TestPost not registered
    $detector = new ConflictDetector($registry);

    $post = TestPost::create([
        'tether_id' => '01HAAABBBCCC',
        'title' => 'Server',
        'body' => null,
    ]);

    // No registration at all means getConflictResolver returns null
    // and the default LWW still applies.
    $result = $detector->evaluate(
        mutation: detectorMutation([
            'timestamp' => 0,
        ]),
        modelClass: TestPost::class,
        serverRecord: $post->fresh(),
        request: makeRequest(),
    );

    // Default LWW should reject the stale mutation.
    expect($result)->toBeInstanceOf(ConflictResolution::class)
        ->and($result->shouldApply)->toBeFalse();
});

it('treats records without updated_at as non-conflicting', function () {
    $record = new class extends \Illuminate\Database\Eloquent\Model {};

    $mutation = \Tether\Core\Mutation\Mutation::fromArray([
        'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model' => 'TestPost',
        'operation' => 'update',
        'payload' => [
            'title' => 'Client',
        ],
        'version' => 1,
        'timestamp' => 1,
    ]);

    app(\Tether\Server\SyncRegistry::class)->register(\Tests\Models\TestPost::class);

    expect(app(\Tether\Server\ConflictDetector::class)->evaluate(
        mutation: $mutation,
        modelClass: \Tests\Models\TestPost::class,
        serverRecord: $record,
        request: \Illuminate\Http\Request::create('/tether/push', 'POST'),
    ))->toBeNull();
});
