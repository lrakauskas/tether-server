<?php

use Illuminate\Support\Facades\DB;
use Tests\Models\TestPost;
use Tether\Core\Enums\OperationType;
use Tether\Core\Mutation\Mutation;
use Tether\Server\Models\ServerMutationLog;
use Tether\Server\SyncRegistry;

// ── Push ──────────────────────────────────────────────────────────────────────

it('push applies mutations and returns applied IDs', function () {
    $payload = [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'create',
                'payload'     => [
                    'title' => 'Hello',
                    'body' => null,
                ],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ];

    $response = $this->postJson(route('tether.push'), $payload);

    $response->assertOk()
        ->assertJsonPath('applied.0', '01HXYZ0000AABBCCDD0011AAB1')
        ->assertJsonPath('rejected', [])
        ->assertJsonPath('conflicts', []);

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeTrue();
    expect(ServerMutationLog::count())->toBe(1);
});

it('push records each applied mutation in the server log', function () {
    $payload = [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'create',
                'payload'     => [
                    'title' => 'Logged',
                    'body' => null,
                ],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ];

    $this->postJson(route('tether.push'), $payload);

    $log = ServerMutationLog::first();

    expect($log->mutation_id)->toBe('01HXYZ0000AABBCCDD0011AAB1')
        ->and($log->client_id)->toBe('client-abc')
        ->and($log->operation)->toBe(OperationType::Create);
});

it('push rejects mutations when the model class does not exist', function () {
    $payload = [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'GhostModel',
                'operation'   => 'create',
                'payload'     => [],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ];

    $response = $this->postJson(route('tether.push'), $payload);

    $response->assertOk()
        ->assertJsonPath('applied', [])
        ->assertJsonPath('rejected.0.mutation_id', '01HXYZ0000AABBCCDD0011AAB1')
        ->assertJsonPath('rejected.0.reason', 'error');
});

it('push returns a validation error when client_id is missing', function () {
    $response = $this->postJson(route('tether.push'), [
        'mutations' => [],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['client_id']);
});

it('push returns a validation error when mutations contain an invalid operation', function () {
    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'explode',
                'payload'     => [],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['mutations.0.operation']);
});

// ── Pull ──────────────────────────────────────────────────────────────────────

it('pull returns all registered model rows as snapshots when no cursor given', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'From Server',
    ]);

    $response = $this->postJson(route('tether.pull'), [
        'client_id' => 'client-abc',
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'snapshots')
        ->assertJsonPath('snapshots.0.model', 'TestPost')
        ->assertJsonPath('snapshots.0.operation', 'upsert')
        ->assertJsonStructure(['snapshots', 'new_sync_cursor']);
});

it('pull returns only rows updated after the given cursor', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    $old = TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Old',
    ]);
    DB::table('test_posts')->where('id', $old->id)->update([
        'updated_at' => now()->subHour(),
    ]);

    $cursor = now()->subMinutes(30)->timestamp * 1_000_000;

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB2',
        'title' => 'New',
    ]);

    $response = $this->postJson(route('tether.pull'), [
        'client_id'        => 'client-abc',
        'last_sync_cursor' => $cursor,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'snapshots')
        ->assertJsonPath('snapshots.0.payload.title', 'New');
});

it('pull returns empty snapshots and preserves cursor when nothing is new', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    $cursor = now()->addHour()->timestamp * 1_000_000;

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Old',
    ]);

    $response = $this->postJson(route('tether.pull'), [
        'client_id'        => 'client-abc',
        'last_sync_cursor' => $cursor,
    ]);

    $response->assertOk()
        ->assertJsonPath('snapshots', [])
        ->assertJsonPath('new_sync_cursor', $cursor);
});

it('pull returns a validation error when client_id is missing', function () {
    $response = $this->postJson(route('tether.pull'), [
        'last_sync_cursor' => null,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['client_id']);
});

it('pull returns a validation error when limit is not an integer', function () {
    $response = $this->postJson(route('tether.pull'), [
        'client_id' => 'client-abc',
        'limit'     => 'many',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['limit']);
});

// ── pushMutationMapper ────────────────────────────────────────────────────────────

it('push applies the pushMutationMapper before writing the mutation payload', function () {
    $registry = $this->app->make(SyncRegistry::class);
    $registry->register(
        TestPost::class,
        pushMutationMapper: function (Mutation $mutation, $request): Mutation {
            $payload = $mutation->getPayload();
            $payload['title'] = strtoupper($payload['title'] ?? '');

            return $mutation->withPayload($payload);
        },
    );

    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'create',
                'payload'     => [
                    'title' => 'hello',
                    'body' => null,
                ],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('applied.0', '01HXYZ0000AABBCCDD0011AAB1');

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))
        ->toBe('HELLO');
});

it('push skips pushMutationMapper when none is registered for the model', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class); // no pushMutationMapper

    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'create',
                'payload'     => [
                    'title' => 'unchanged',
                    'body' => null,
                ],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ]);

    $response->assertOk();

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))
        ->toBe('unchanged');
});

// ── pull pagination ───────────────────────────────────────────────────────────


it('push runs the pushMutationMapper before conflict detection', function () {
    $capturedTitle = null;

    $this->app->make(SyncRegistry::class)->register(
        TestPost::class,
        pushMutationMapper: function (Mutation $mutation): Mutation {
            return $mutation->withPayload(array_merge($mutation->getPayload(), [
                'title' => 'Mapped Before Conflict',
            ]));
        },
        conflictResolver: function (Mutation $mutation) use (&$capturedTitle) {
            $capturedTitle = $mutation->getPayload()['title'] ?? null;

            return \Tether\Core\Conflict\ConflictResolution::reject();
        },
    );

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Server',
        'body' => null,
    ]);

    $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'update',
                'payload'     => [
                    'title' => 'Original Client Title',
                ],
                'version'     => 2,
                'timestamp'   => 0,
            ],
        ],
    ])->assertOk()
        ->assertJsonCount(1, 'conflicts');

    expect($capturedTitle)->toBe('Mapped Before Conflict');
});
it('pull respects a limit and returns has_more true when more rows exist', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    for ($i = 1; $i <= 5; $i++) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
        ]);
    }

    $response = $this->postJson(route('tether.pull'), [
        'client_id' => 'client-abc',
        'limit'     => 3,
    ]);

    $response->assertOk()
        ->assertJsonCount(3, 'snapshots')
        ->assertJsonPath('has_more', true);
});

it('pull returns has_more false when all rows fit within the limit', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'One',
    ]);

    $response = $this->postJson(route('tether.pull'), [
        'client_id' => 'client-abc',
        'limit'     => 10,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'snapshots')
        ->assertJsonPath('has_more', false);
});

// ── Version headers ───────────────────────────────────────────────────────────

it('push response includes the X-Tether-Server-Version header', function () {
    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'create',
                'payload'     => [
                    'title' => 'Header Test',
                    'body' => null,
                ],
                'version'     => 1,
                'timestamp'   => 1000000,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertHeader('X-Tether-Server-Version');
});

it('pull response includes the X-Tether-Server-Version header', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    $response = $this->postJson(route('tether.pull'), [
        'client_id' => 'client-abc',
    ]);

    $response->assertOk()
        ->assertHeader('X-Tether-Server-Version');
});

// ── Conflict detection ────────────────────────────────────────────────────────

it('push detects a conflict when mutation timestamp is older than server record', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    // Create a record on the server with a recent timestamp
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Server State',
        'body' => null,
    ]);

    // Simulate a stale mutation (timestamp = 0, far in the past)
    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'update',
                'payload'     => [
                    'title' => 'Stale Client State',
                ],
                'version'     => 2,
                'timestamp'   => 0,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('applied', [])
        ->assertJsonPath('rejected', [])
        ->assertJsonCount(1, 'conflicts')
        ->assertJsonPath('conflicts.0.mutation_id', '01HXYZ0000AABBCCDD0011AAB1')
        ->assertJsonPath('conflicts.0.reason', 'conflict');

    // Server state should be unchanged
    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))
        ->toBe('Server State');
});

it('push conflict response contains server_state in data', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Server',
        'body' => null,
    ]);

    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'update',
                'payload'     => [
                    'title' => 'Old',
                ],
                'version'     => 2,
                'timestamp'   => 0,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'conflicts' => [[
                'mutation_id',
                'reason',
                'data' => ['server_state', 'server_updated_at'],
            ]],
        ]);
});

it('push applies mutation when custom conflict resolver returns apply', function () {
    // Register with a client-always-wins resolver
    $this->app->make(SyncRegistry::class)->register(
        TestPost::class,
        conflictResolver: fn(Mutation $mutation, $record, $request) => \Tether\Core\Conflict\ConflictResolution::apply($mutation->getPayload()),
    );

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Server',
        'body' => null,
    ]);

    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'update',
                'payload'     => [
                    'title' => 'Client Wins',
                ],
                'version'     => 2,
                'timestamp'   => 0,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('applied.0', '01HXYZ0000AABBCCDD0011AAB1')
        ->assertJsonPath('conflicts', []);

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))
        ->toBe('Client Wins');
});

it('push does not flag a conflict for create operations even with a stale timestamp', function () {
    $this->app->make(SyncRegistry::class)->register(TestPost::class);

    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [
            [
                'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
                'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
                'model'       => 'TestPost',
                'operation'   => 'create',
                'payload'     => [
                    'title' => 'New',
                    'body' => null,
                ],
                'version'     => 1,
                'timestamp'   => 0,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('applied.0', '01HXYZ0000AABBCCDD0011AAB1')
        ->assertJsonPath('conflicts', []);
});

// ── Idempotency ───────────────────────────────────────────────────────────────

it('push treats a previously-applied mutation as applied without re-applying it', function () {
    $mutationId = '01HXYZ0000AABBCCDD0011AAB1';
    $entityId   = '01HXYZ0001AABBCCDD0011AAB1';

    $mutation = [
        'mutation_id' => $mutationId,
        'entity_id'   => $entityId,
        'model'       => 'TestPost',
        'operation'   => 'create',
        'payload'     => [
            'title' => 'First',
            'body' => null,
        ],
        'version'     => 1,
        'timestamp'   => 1000000,
    ];

    // First push - applied normally.
    $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [$mutation],
    ])->assertOk()->assertJsonPath('applied.0', $mutationId);

    // Second push with same mutation - treated as applied without re-applying.
    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [$mutation],
    ]);

    $response->assertOk()
        ->assertJsonPath('applied.0', $mutationId)
        ->assertJsonPath('rejected', [])
        ->assertJsonPath('conflicts', []);

    // Should still be only one log entry and one DB row.
    expect(ServerMutationLog::where('mutation_id', $mutationId)->count())->toBe(1);
    expect(TestPost::where('tether_id', $entityId)->count())->toBe(1);
});

it('push handles within-batch duplicate mutation IDs idempotently', function () {
    $mutationId = '01HXYZ0000AABBCCDD0011AAB1';
    $entityId   = '01HXYZ0001AABBCCDD0011AAB1';

    $mutation = [
        'mutation_id' => $mutationId,
        'entity_id'   => $entityId,
        'model'       => 'TestPost',
        'operation'   => 'create',
        'payload'     => [
            'title' => 'Once',
            'body' => null,
        ],
        'version'     => 1,
        'timestamp'   => 1000000,
    ];

    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [$mutation, $mutation], // same ID twice in one batch
    ]);

    $response->assertOk()
        ->assertJsonPath('rejected', [])
        ->assertJsonPath('conflicts', []);

    // Both should appear in applied (second is idempotent success), no DB errors.
    expect($response->json('applied'))->toHaveCount(2)->each->toBe($mutationId);

    // Only one log entry and one DB row should exist.
    expect(ServerMutationLog::where('mutation_id', $mutationId)->count())->toBe(1);
    expect(TestPost::where('tether_id', $entityId)->count())->toBe(1);
});

it('push rejects duplicate mutations when strict_duplicates is enabled', function () {
    config([
        'tether-server.strict_duplicates' => true,
    ]);

    $mutationId = '01HXYZ0000AABBCCDD0011AAB1';

    $mutation = [
        'mutation_id' => $mutationId,
        'entity_id'   => '01HXYZ0001AABBCCDD0011AAB1',
        'model'       => 'TestPost',
        'operation'   => 'create',
        'payload'     => [
            'title' => 'Strict',
            'body' => null,
        ],
        'version'     => 1,
        'timestamp'   => 1000000,
    ];

    // First push - applied.
    $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [$mutation],
    ])->assertOk();

    // Second push - rejected as duplicate in strict mode.
    $response = $this->postJson(route('tether.push'), [
        'client_id' => 'client-abc',
        'mutations' => [$mutation],
    ]);

    $response->assertOk()
        ->assertJsonPath('applied', [])
        ->assertJsonPath('rejected.0.mutation_id', $mutationId)
        ->assertJsonPath('rejected.0.reason', 'duplicate');
});
