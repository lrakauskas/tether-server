<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Tests\Models\TestPost;
use Tests\Models\TestPostWithSoftDeletes;
use Tether\Core\Sync\PullRequest;
use Tether\Core\Sync\Snapshot;
use Tether\Server\StateSnapshotGenerator;
use Tether\Server\SyncRegistry;

function makeGenerator(?callable $scope = null, ?callable $pullSnapshotMapper = null, bool $softDeletes = false): StateSnapshotGenerator
{
    $registry = new SyncRegistry();
    $modelClass = $softDeletes ? TestPostWithSoftDeletes::class : TestPost::class;
    $registry->register($modelClass, $scope, $pullSnapshotMapper);

    return new StateSnapshotGenerator($registry);
}

function fakeRequest(string $clientId = 'client-1'): Request
{
    $request = Request::create('/tether/pull', 'POST', [
        'client_id' => $clientId,
    ]);

    return $request;
}

function snapshotResult(StateSnapshotGenerator $generator, ?int $cursor = null, ?int $limit = null, string $clientId = 'client-1'): array
{
    return $generator
        ->since(new PullRequest($clientId, $cursor, $limit), fakeRequest($clientId))
        ->toArray();
}

it('returns empty snapshots and null cursor when no registered models have rows', function () {
    $generator = makeGenerator();

    $result = snapshotResult($generator);

    expect($result['snapshots'])->toBeEmpty()
        ->and($result['new_sync_cursor'])->toBeNull();
});

it('returns all rows as upsert snapshots when cursor is null', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Hello',
    ]);
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB2',
        'title' => 'World',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator);

    expect($result['snapshots'])->toHaveCount(2)
        ->and($result['snapshots'][0]['operation'])->toBe('upsert')
        ->and($result['snapshots'][1]['operation'])->toBe('upsert');
});

it('returns snapshot with correct entity_id and model name', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Hello',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator);

    expect($result['snapshots'][0]['entity_id'])->toBe('01HXYZ0001AABBCCDD0011AAB1')
        ->and($result['snapshots'][0]['model'])->toBe('TestPost');
});

it('excludes rows whose updated_at is not after the cursor', function () {
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

    $generator = makeGenerator();
    $result    = snapshotResult($generator, $cursor);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['snapshots'][0]['payload']['title'])->toBe('New');
});

it('advances the cursor to the max updated_at of returned rows', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Post',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator);

    expect($result['new_sync_cursor'])->not->toBeNull();
});

it('keeps the old cursor when no rows match the filter', function () {
    $cursor = now()->addHour()->timestamp * 1_000_000; // future cursor - nothing is newer

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Old',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator, $cursor);

    expect($result['snapshots'])->toBeEmpty()
        ->and($result['new_sync_cursor'])->toBe($cursor);
});

it('applies the scope callback to restrict returned rows', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Visible',
    ]);
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB2',
        'title' => 'Hidden',
    ]);

    $scope = fn($query) => $query->where('title', 'Visible');

    $generator = makeGenerator($scope);
    $result    = snapshotResult($generator);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['snapshots'][0]['payload']['title'])->toBe('Visible');
});

it('applies the pull snapshot mapper to transform row attributes', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Hello',
        'body' => 'secret',
    ]);

    $mapper    = fn(Snapshot $snapshot) => $snapshot->withPayload(array_diff_key($snapshot->payload, [
        'body' => true,
    ]));
    $generator = makeGenerator(null, $mapper);
    $result    = snapshotResult($generator);

    expect($result['snapshots'][0]['payload'])->not->toHaveKey('body')
        ->and($result['snapshots'][0]['payload']['title'])->toBe('Hello');
});

it('strips the server primary key from the payload', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Hello',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator);

    expect($result['snapshots'][0]['payload'])->not->toHaveKey('id');
});

it('uses the configured sync key column for snapshot entity ids', function () {
    config([
        'tether-server.sync_key' => 'sync_id',
    ]);

    Schema::table('test_posts', function ($table): void {
        $table->char('sync_id', 26)->nullable()->unique();
    });

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'sync_id' => '01HXYZ0001AABBCCDD0011SYN1',
        'title' => 'Custom key',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['snapshots'][0]['entity_id'])->toBe('01HXYZ0001AABBCCDD0011SYN1')
        ->and($result['snapshots'][0]['payload'])->toHaveKey('sync_id')
        ->and($result['snapshots'][0]['payload'])->not->toHaveKey('id');
});

it('returns soft-deleted rows as delete snapshots', function () {
    $post = TestPostWithSoftDeletes::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Gone',
    ]);
    $post->delete();

    $generator = makeGenerator(softDeletes: true);
    $result    = snapshotResult($generator);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['snapshots'][0]['operation'])->toBe('delete')
        ->and($result['snapshots'][0]['entity_id'])->toBe('01HXYZ0001AABBCCDD0011AAB1');
});

it('includes soft-deleted rows matching the cursor filter via deleted_at', function () {
    $post = TestPostWithSoftDeletes::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Deleted',
    ]);
    // Back-date updated_at so it won't match the cursor via updated_at
    DB::table('test_posts_soft')->where('id', $post->id)->update([
        'updated_at' => now()->subHour(),
    ]);

    $cursor = now()->subMinutes(30)->timestamp * 1_000_000;

    // delete() sets deleted_at = now(), which is after the cursor
    $post->refresh()->delete();

    $generator = makeGenerator(softDeletes: true);
    $result    = snapshotResult($generator, $cursor);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['snapshots'][0]['operation'])->toBe('delete');
});

// ── has_more / pagination ─────────────────────────────────────────────────────

it('returns has_more false when limit is null', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'A',
    ]);
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB2',
        'title' => 'B',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator, null, null);

    expect($result['has_more'])->toBeFalse()
        ->and($result['snapshots'])->toHaveCount(2);
});

it('returns has_more false when rows are within the limit', function () {
    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'A',
    ]);

    $generator = makeGenerator();
    $result    = snapshotResult($generator, null, 5);

    expect($result['has_more'])->toBeFalse()
        ->and($result['snapshots'])->toHaveCount(1);
});

it('returns has_more true and slices to limit when rows exceed the limit', function () {
    for ($i = 1; $i <= 5; $i++) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
        ]);
    }

    $generator = makeGenerator();
    $result    = snapshotResult($generator, null, 3);

    expect($result['has_more'])->toBeTrue()
        ->and($result['snapshots'])->toHaveCount(3);
});

it('returns exactly limit snapshots when the row count equals the limit', function () {
    for ($i = 1; $i <= 3; $i++) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
        ]);
    }

    $generator = makeGenerator();
    $result    = snapshotResult($generator, null, 3);

    expect($result['has_more'])->toBeFalse()
        ->and($result['snapshots'])->toHaveCount(3);
});

it('advances cursor only to the last included snapshot when has_more is true', function () {
    for ($i = 1; $i <= 5; $i++) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
        ]);
    }

    $generator = makeGenerator();
    $result    = snapshotResult($generator, null, 3);

    // Cursor must be set (rows were included) and not null
    expect($result['has_more'])->toBeTrue()
        ->and($result['new_sync_cursor'])->not->toBeNull();
});

it('stops scanning later registrations once the pull limit is already satisfied', function () {
    app(\Tether\Server\SyncRegistry::class)->register(TestPost::class);
    app(\Tether\Server\SyncRegistry::class)->register(TestPostWithSoftDeletes::class);

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011LIM1',
        'title' => 'First',
    ]);
    TestPostWithSoftDeletes::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011LIM2',
        'title' => 'Second',
    ]);

    $result = app(\Tether\Server\StateSnapshotGenerator::class)->since(
        \Tether\Core\Sync\PullRequest::fromArray([
            'client_id' => 'client-abc',
            'limit' => 1,
        ]),
        \Illuminate\Http\Request::create('/tether/pull', 'POST'),
    );

    expect($result->snapshots)->toHaveCount(1)
        ->and($result->hasMore)->toBeTrue();
});

it('skips rows without a tether id', function () {
    app(\Tether\Server\SyncRegistry::class)->register(TestPost::class);

    TestPost::query()->create([
        'title' => 'No tether id',
        'body' => null,
    ]);

    $result = app(\Tether\Server\StateSnapshotGenerator::class)->since(
        \Tether\Core\Sync\PullRequest::fromArray([
            'client_id' => 'client-abc',
        ]),
        \Illuminate\Http\Request::create('/tether/pull', 'POST'),
    );

    expect($result->snapshots)->toBeEmpty();
});

it('advances soft-delete cursors using deleted_at when it is newer than updated_at', function () {
    app(\Tether\Server\SyncRegistry::class)->register(TestPostWithSoftDeletes::class);

    $post = TestPostWithSoftDeletes::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011DEL1',
        'title' => 'Delete cursor',
    ]);
    $post->delete();

    $deletedAt = now()->startOfSecond();
    TestPostWithSoftDeletes::withTrashed()
        ->whereKey($post->id)
        ->update([
            'updated_at' => $deletedAt->copy()->subHour(),
            'deleted_at' => $deletedAt,
        ]);

    $result = app(\Tether\Server\StateSnapshotGenerator::class)->since(
        \Tether\Core\Sync\PullRequest::fromArray([
            'client_id' => 'client-abc',
        ]),
        \Illuminate\Http\Request::create('/tether/pull', 'POST'),
    );

    expect($result->newSyncCursor)->toBe((int) $deletedAt->getPreciseTimestamp(6));
});
