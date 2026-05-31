<?php

use Illuminate\Support\Facades\Event;
use Tests\Models\TestPost;
use Tether\Core\Sync\PushRequest;
use Tether\Server\Events\PullSyncCompleted;
use Tether\Server\Events\PullSyncStarted;
use Tether\Server\Events\PushMutationProcessed;
use Tether\Server\Events\PushSyncCompleted;
use Tether\Server\Events\PushSyncStarted;
use Tether\Server\PushSyncProcessor;
use Tether\Server\SyncRegistry;

it('dispatches push lifecycle and mutation events without changing the push result', function () {
    Event::fake();

    $result = app(PushSyncProcessor::class)->process(PushRequest::fromArray([
        'client_id' => 'client-events',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011EV01',
                'entity_id' => '01HXYZ0001AABBCCDD0011EV01',
                'model' => 'TestPost',
                'operation' => 'create',
                'payload' => [
                    'title' => 'Evented',
                    'body' => null,
                ],
                'version' => 1,
                'timestamp' => 1000000,
            ],
        ],
    ]), request());

    expect($result->toArray())->toMatchArray([
        'applied' => ['01HXYZ0000AABBCCDD0011EV01'],
        'rejected' => [],
        'conflicts' => [],
    ]);

    Event::assertDispatched(PushSyncStarted::class, fn(PushSyncStarted $event): bool => $event->pushRequest->clientId === 'client-events');
    Event::assertDispatched(PushMutationProcessed::class, fn(PushMutationProcessed $event): bool => $event->outcome === 'applied'
        && $event->mutation->getMutationId() === '01HXYZ0000AABBCCDD0011EV01'
        && $event->serverStateAfter['title'] === 'Evented');
    Event::assertDispatched(PushSyncCompleted::class, fn(PushSyncCompleted $event): bool => $event->result->applied === ['01HXYZ0000AABBCCDD0011EV01']
        && $event->durationMs >= 0);
});

it('dispatches per-mutation events for rejected duplicates conflicts and errors', function () {
    Event::fake();
    config([
        'tether-server.strict_duplicates' => true,
    ]);

    app(SyncRegistry::class)->register(TestPost::class);
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011EV02',
        'title' => 'Server wins',
        'body' => null,
    ]);

    $processor = app(PushSyncProcessor::class);
    $duplicate = [
        'mutation_id' => '01HXYZ0000AABBCCDD0011EV02',
        'entity_id' => '01HXYZ0001AABBCCDD0011EV03',
        'model' => 'TestPost',
        'operation' => 'create',
        'payload' => [
            'title' => 'First',
        ],
        'version' => 1,
        'timestamp' => 1000000,
    ];

    $processor->process(PushRequest::fromArray([
        'client_id' => 'client-events',
        'mutations' => [$duplicate],
    ]), request());

    $processor->process(PushRequest::fromArray([
        'client_id' => 'client-events',
        'mutations' => [
            $duplicate,
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011EV03',
                'entity_id' => '01HXYZ0001AABBCCDD0011EV02',
                'model' => 'TestPost',
                'operation' => 'update',
                'payload' => [
                    'title' => 'Client stale',
                ],
                'version' => 2,
                'timestamp' => 0,
            ],
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011EV04',
                'entity_id' => '01HXYZ0001AABBCCDD0011EV04',
                'model' => 'MissingModel',
                'operation' => 'create',
                'payload' => [],
                'version' => 1,
                'timestamp' => 1000000,
            ],
        ],
    ]), request());

    Event::assertDispatched(PushMutationProcessed::class, fn(PushMutationProcessed $event): bool => $event->mutation->getMutationId() === '01HXYZ0000AABBCCDD0011EV02'
        && $event->outcome === 'rejected'
        && $event->reason === 'duplicate');
    Event::assertDispatched(PushMutationProcessed::class, fn(PushMutationProcessed $event): bool => $event->mutation->getMutationId() === '01HXYZ0000AABBCCDD0011EV03'
        && $event->outcome === 'conflict'
        && $event->serverStateBefore['title'] === 'Server wins');
    Event::assertDispatched(PushMutationProcessed::class, fn(PushMutationProcessed $event): bool => $event->mutation->getMutationId() === '01HXYZ0000AABBCCDD0011EV04'
        && $event->outcome === 'rejected'
        && $event->reason === 'error');
});

it('dispatches pull lifecycle events with result telemetry', function () {
    Event::fake();

    app(SyncRegistry::class)->register(TestPost::class);
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011EV05',
        'title' => 'Pull event',
        'body' => null,
    ]);

    $response = $this->postJson(route('tether.pull'), [
        'client_id' => 'client-events',
    ]);

    $response->assertOk()->assertJsonCount(1, 'snapshots');

    Event::assertDispatched(PullSyncStarted::class, fn(PullSyncStarted $event): bool => $event->pullRequest->clientId === 'client-events');
    Event::assertDispatched(PullSyncCompleted::class, fn(PullSyncCompleted $event): bool => count($event->result->snapshots) === 1
        && $event->durationMs >= 0);
});
