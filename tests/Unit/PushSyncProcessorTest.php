<?php

use Illuminate\Http\Request;
use Tests\Models\StaticWithTrashedPost;
use Tests\Models\TestPost;
use Tether\Core\Exceptions\EntityNotFoundException;
use Tether\Core\Exceptions\MutationValidationException;
use Tether\Core\Conflict\ConflictResolution;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\MutationApplicator;
use Tether\Core\Sync\PushRequest;
use Tether\Server\ConflictDetector;
use Tether\Server\Models\ServerMutationLog;
use Tether\Server\PushSyncProcessor;
use Tether\Server\SyncRegistry;

function pushProcessorPayload(array $overrides = []): array
{
    return array_merge([
        'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model' => 'TestPost',
        'operation' => 'create',
        'payload' => [
            'title' => 'Hello',
            'body' => null,
        ],
        'version' => 1,
        'timestamp' => 1000000,
    ], $overrides);
}

function processPush(array $mutations, string $clientId = 'client-abc'): array
{
    return app(PushSyncProcessor::class)->process(PushRequest::fromArray([
        'client_id' => $clientId,
        'mutations' => $mutations,
    ]), Request::create('/tether/push', 'POST'))->toArray();
}

it('applies mutations and records them in the server log', function () {
    $result = processPush([pushProcessorPayload()]);

    expect($result['applied'])->toBe(['01HXYZ0000AABBCCDD0011AAB1'])
        ->and($result['rejected'])->toBeEmpty()
        ->and($result['conflicts'])->toBeEmpty()
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeTrue()
        ->and(ServerMutationLog::where('mutation_id', '01HXYZ0000AABBCCDD0011AAB1')->exists())->toBeTrue();
});

it('treats previously applied mutations as applied by default', function () {
    $mutation = pushProcessorPayload();

    processPush([$mutation]);
    $result = processPush([$mutation]);

    expect($result['applied'])->toBe([$mutation['mutation_id']])
        ->and($result['rejected'])->toBeEmpty()
        ->and(ServerMutationLog::where('mutation_id', $mutation['mutation_id'])->count())->toBe(1);
});

it('treats within-batch duplicate mutations as applied by default', function () {
    $mutation = pushProcessorPayload();

    $result = processPush([$mutation, $mutation]);

    expect($result['applied'])->toBe([$mutation['mutation_id'], $mutation['mutation_id']])
        ->and($result['rejected'])->toBeEmpty()
        ->and(ServerMutationLog::where('mutation_id', $mutation['mutation_id'])->count())->toBe(1);
});

it('rejects duplicates when strict duplicates are enabled', function () {
    config([
        'tether-server.strict_duplicates' => true,
    ]);
    $mutation = pushProcessorPayload();

    processPush([$mutation]);
    $result = processPush([$mutation]);

    expect($result['applied'])->toBeEmpty()
        ->and($result['rejected'][0]['mutation_id'])->toBe($mutation['mutation_id'])
        ->and($result['rejected'][0]['reason'])->toBe('duplicate');
});

it('returns conflicts when server state wins', function () {
    app(SyncRegistry::class)->register(TestPost::class);
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Server State',
        'body' => null,
    ]);

    $result = processPush([
        pushProcessorPayload([
            'operation' => 'update',
            'payload' => [
                'title' => 'Stale Client State',
            ],
            'version' => 2,
            'timestamp' => 0,
        ])]);

    expect($result['applied'])->toBeEmpty()
        ->and($result['rejected'])->toBeEmpty()
        ->and($result['conflicts'])->toHaveCount(1)
        ->and($result['conflicts'][0]['reason'])->toBe('conflict')
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))->toBe('Server State');
});

it('applies conflicts when a custom resolver allows the mutation', function () {
    app(SyncRegistry::class)->register(
        TestPost::class,
        conflictResolver: fn(Mutation $mutation, $record, $request) => ConflictResolution::apply($mutation->getPayload()),
    );

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Server State',
        'body' => null,
    ]);

    $result = processPush([
        pushProcessorPayload([
            'operation' => 'update',
            'payload' => [
                'title' => 'Client State',
            ],
            'version' => 2,
            'timestamp' => 0,
        ])]);

    expect($result['applied'])->toBe(['01HXYZ0000AABBCCDD0011AAB1'])
        ->and($result['conflicts'])->toBeEmpty()
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))->toBe('Client State');
});

it('rejects processor errors without aborting the batch', function () {
    $result = processPush([
        pushProcessorPayload([
            'model' => 'MissingModel',
        ])]);

    expect($result['applied'])->toBeEmpty()
        ->and($result['rejected'][0]['mutation_id'])->toBe('01HXYZ0000AABBCCDD0011AAB1')
        ->and($result['rejected'][0]['reason'])->toBe('error');
});

it('rejects validation exceptions with structured messages', function () {
    $applicator = \Mockery::mock(MutationApplicator::class);
    $applicator->shouldReceive('apply')
        ->once()
        ->andThrow(new MutationValidationException([
            'title' => ['Required.'],
        ], '01HXYZ0000AABBCCDD0011AAB1'));

    $processor = new PushSyncProcessor($applicator, new SyncRegistry(), app(ConflictDetector::class));

    $result = $processor->process(PushRequest::fromArray([
        'client_id' => 'client-abc',
        'mutations' => [pushProcessorPayload()],
    ]), Request::create('/tether/push', 'POST'))->toArray();

    expect($result['rejected'][0]['reason'])->toBe('validation_failed')
        ->and($result['rejected'][0]['data']['messages'])->toBe([
            'title' => ['Required.'],
        ]);
});

it('rejects entity not found exceptions with model context', function () {
    $applicator = \Mockery::mock(MutationApplicator::class);
    $applicator->shouldReceive('apply')
        ->once()
        ->andThrow(new EntityNotFoundException('TestPost', '01HXYZ0001AABBCCDD0011AAB1'));

    $processor = new PushSyncProcessor($applicator, new SyncRegistry(), app(ConflictDetector::class));

    $result = $processor->process(PushRequest::fromArray([
        'client_id' => 'client-abc',
        'mutations' => [
            pushProcessorPayload([
                'operation' => 'update',
            ])],
    ]), Request::create('/tether/push', 'POST'))->toArray();

    expect($result['rejected'][0]['reason'])->toBe('not_found')
        ->and($result['rejected'][0]['data'])->toBe([
            'model' => 'TestPost',
            'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        ]);
});

it('uses withTrashed-style lookups when the model exposes them', function () {
    app(SyncRegistry::class)->register(StaticWithTrashedPost::class);

    StaticWithTrashedPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011TRA1',
        'title' => 'With trashed lookup',
    ]);

    $result = processPush([
        pushProcessorPayload([
            'entity_id' => '01HXYZ0001AABBCCDD0011TRA1',
            'model' => 'StaticWithTrashedPost',
            'operation' => 'update',
            'payload' => [
                'title' => 'Client update',
            ],
            'version' => 2,
            'timestamp' => 0,
        ])]);

    expect($result['conflicts'])->toHaveCount(1)
        ->and($result['conflicts'][0]['data']['server_state']['title'])->toBe('With trashed lookup');
});
