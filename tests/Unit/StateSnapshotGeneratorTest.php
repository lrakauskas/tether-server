<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Tests\Models\TestPost;
use Tests\Models\TestPostMicro;
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

it('paginates complete groups with a custom sync key and excludes rows without that key', function () {
    config(['tether-server.sync_key' => 'sync_id']);
    Schema::table('test_posts', function ($table): void {
        $table->char('sync_id', 26)->nullable()->unique();
    });
    $time = now()->subHour()->startOfSecond();
    TestPost::create(['title' => 'Missing sync ID', 'updated_at' => $time]);
    for ($i = 1; $i <= 3; $i++) {
        TestPost::create([
            'sync_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
            'updated_at' => $time->copy()->addSeconds($i === 3 ? 2 : 1),
        ]);
    }

    $generator = makeGenerator();
    $first = snapshotResult($generator, null, 1);
    $last = snapshotResult($generator, $first['new_sync_cursor'], 1);
    expect($first['snapshots'])->toHaveCount(2)
        ->and(array_column($first['snapshots'], 'entity_id'))->toBe([
            '01HXYZ0001AABBCCDD0000000001', '01HXYZ0001AABBCCDD0000000002',
        ])
        ->and($first['has_more'])->toBeTrue()
        ->and($last['snapshots'])->toHaveCount(1)
        ->and($last['has_more'])->toBeFalse();
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
    $time = now()->subHour()->startOfSecond();
    for ($i = 1; $i <= 5; $i++) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
            'updated_at' => $time->copy()->addSeconds($i),
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
    $time = now()->subHour()->startOfSecond();
    for ($i = 1; $i <= 5; $i++) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Post {$i}",
            'updated_at' => $time->copy()->addSeconds($i),
        ]);
    }

    $generator = makeGenerator();
    $result    = snapshotResult($generator, null, 3);

    // Cursor must be set (rows were included) and not null
    expect($result['has_more'])->toBeTrue()
        ->and($result['new_sync_cursor'])->not->toBeNull();
});

it('paginates globally across models without skipping earlier changes in later registrations', function (bool $reverse) {
    $models = [TestPost::class, TestPostWithSoftDeletes::class];
    foreach ($reverse ? array_reverse($models) : $models as $model) {
        app(SyncRegistry::class)->register($model);
    }

    $base = now()->subHour()->startOfSecond();
    for ($i = 1; $i <= 15; $i++) {
        $row = TestPost::create(['tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i), 'title' => "Post {$i}"]);
        DB::table('test_posts')->where('id', $row->id)->update(['updated_at' => $base->copy()->addSeconds($i)]);
    }
    for ($i = 1; $i <= 30; $i++) {
        $row = TestPostWithSoftDeletes::create(['tether_id' => sprintf('01HXYZ0001AABBCCDD01%08d', $i), 'title' => "Comment {$i}"]);
        DB::table('test_posts_soft')->where('id', $row->id)->update(['updated_at' => $base->copy()->addSeconds($i)]);
    }

    $generator = app(StateSnapshotGenerator::class);
    $cursor = null;
    $seen = [];
    do {
        $page = snapshotResult($generator, $cursor, 10);
        expect($page['new_sync_cursor'])->toBeInt()->toBeGreaterThan($cursor ?? 0);
        expect($page['snapshots'])->toHaveCount(count($seen) < 40 ? 10 : 5);
        foreach ($page['snapshots'] as $snapshot) {
            $seen[] = $snapshot['entity_id'];
        }
        $cursor = $page['new_sync_cursor'];
    } while ($page['has_more']);

    expect($seen)->toHaveCount(45)
        ->and(array_unique($seen))->toHaveCount(45)
        ->and($seen)->toContain('01HXYZ0001AABBCCDD0100000001');
})->with([false, true]);

it('returns the entire timestamp group across models even when it exceeds the limit', function () {
    $registry = new SyncRegistry();
    $registry->register(TestPost::class);
    $registry->register(TestPostWithSoftDeletes::class);
    $generator = new StateSnapshotGenerator($registry);
    $time = now()->subMinute()->startOfSecond();

    for ($i = 1; $i <= 7; $i++) {
        $row = TestPost::create(['tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i), 'title' => "Post {$i}"]);
        DB::table('test_posts')->where('id', $row->id)->update(['updated_at' => $time]);
        $row = TestPostWithSoftDeletes::create(['tether_id' => sprintf('01HXYZ0001AABBCCDD01%08d', $i), 'title' => "Comment {$i}"]);
        DB::table('test_posts_soft')->where('id', $row->id)->update(['updated_at' => $time]);
    }

    $page = snapshotResult($generator, null, 3);
    $next = snapshotResult($generator, $page['new_sync_cursor'], 3);

    expect($page['snapshots'])->toHaveCount(14)
        ->and(array_unique(array_column($page['snapshots'], 'entity_id')))->toHaveCount(14)
        ->and($page['new_sync_cursor'])->toBe((int) $time->getPreciseTimestamp(6))
        ->and($page['has_more'])->toBeFalse()
        ->and($next['snapshots'])->toBeEmpty()
        ->and($next['new_sync_cursor'])->toBe($page['new_sync_cursor']);
});

it('only advances past complete timestamp groups', function (array $groups, int $limit, array $pageSizes) {
    $time = now()->subHour()->startOfSecond();
    $id = 0;
    foreach ($groups as $second => $count) {
        for ($i = 0; $i < $count; $i++) {
            TestPost::create([
                'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', ++$id),
                'title' => "Row {$id}",
                'updated_at' => $time->copy()->addSeconds($second),
            ]);
        }
    }

    $generator = makeGenerator();
    $cursor = null;
    $seen = [];
    $sizes = [];
    do {
        $page = snapshotResult($generator, $cursor, $limit);
        expect($page['new_sync_cursor'])->toBeInt()->toBeGreaterThan($cursor ?? 0);
        expect(snapshotResult($generator, $cursor, $limit))->toBe($page);
        $sizes[] = count($page['snapshots']);
        expect(count($sizes))->toBeLessThanOrEqual(count($groups));
        $seen = array_merge($seen, array_column($page['snapshots'], 'entity_id'));
        $cursor = $page['new_sync_cursor'];
    } while ($page['has_more']);

    expect($sizes)->toBe($pageSizes)
        ->and(count($seen))->toBe(array_sum($groups))
        ->and(array_unique($seen))->toHaveCount(array_sum($groups));
})->with([
    'smaller groups' => [[1, 1, 1, 1, 1], 2, [2, 2, 1]],
    'exact boundary' => [[2, 3, 4], 5, [5, 4]],
    'overflow followed by another page' => [[2, 5, 1], 3, [7, 1]],
    'one large group' => [[12], 3, [12]],
]);

it('uses client scopes for boundary selection expansion and has_more and maps only returned rows', function () {
    $time = now()->subHour()->startOfSecond();
    foreach (['Hidden', 'Visible', 'Visible', 'Visible', 'Hidden'] as $i => $title) {
        TestPost::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i + 1),
            'title' => $title,
            'updated_at' => $time->copy()->addSeconds($i === 2 ? 1 : $i),
        ]);
    }
    $mapped = [];
    $scope = function ($query, string $clientId, Request $request) {
        expect($clientId)->toBe('client-1')->and($request->input('client_id'))->toBe('client-1');
        return $query->where('title', 'Visible')->orderByDesc('id');
    };
    $generator = makeGenerator($scope, function (Snapshot $snapshot) use (&$mapped): Snapshot {
        $mapped[] = $snapshot->entityId;
        return $snapshot->withPayload(['title' => 'Mapped']);
    });

    $first = snapshotResult($generator, null, 1);
    expect($first['snapshots'])->toHaveCount(2)
        ->and($mapped)->toBe(array_column($first['snapshots'], 'entity_id'))
        ->and($first['snapshots'][0]['payload'])->toBe(['title' => 'Mapped'])
        ->and($first['has_more'])->toBeTrue();
    $last = snapshotResult($generator, $first['new_sync_cursor'], 1);
    expect($last['snapshots'])->toHaveCount(1)->and($last['has_more'])->toBeFalse();
});

it('resumes correctly when a model stores fractional timestamp precision', function () {
    $registry = new SyncRegistry();
    $registry->register(TestPostMicro::class);
    $generator = new StateSnapshotGenerator($registry);
    $time = now()->subMinute()->startOfSecond()->addMicroseconds(123456);

    for ($i = 1; $i <= 3; $i++) {
        $row = TestPostMicro::create(['tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i), 'title' => "Post {$i}"]);
        DB::table('test_posts')->where('id', $row->id)->update(['updated_at' => ($i === 3 ? $time->copy()->addMicrosecond() : $time)->format('Y-m-d H:i:s.u')]);
    }

    $first = snapshotResult($generator, null, 2);
    $second = snapshotResult($generator, $first['new_sync_cursor'], 2);

    expect($first['snapshots'])->toHaveCount(2)
        ->and($first['has_more'])->toBeTrue()
        ->and($second['snapshots'])->toHaveCount(1)
        ->and($second['snapshots'][0]['entity_id'])->toBe('01HXYZ0001AABBCCDD0000000003')
        ->and($second['has_more'])->toBeFalse()
        ->and($second['new_sync_cursor'])->toBe((int) $time->copy()->addMicrosecond()->getPreciseTimestamp(6));
});

it('orders soft deletes by deleted_at when it is later than updated_at', function () {
    $registry = new SyncRegistry();
    $registry->register(TestPostWithSoftDeletes::class);
    $registry->register(TestPost::class);
    $generator = new StateSnapshotGenerator($registry);
    $time = now()->subHour()->startOfSecond();

    $deleted = TestPostWithSoftDeletes::create(['tether_id' => '01HXYZ0001AABBCCDD0011DEL1', 'title' => 'Deleted']);
    $deleted->delete();
    DB::table('test_posts_soft')->where('id', $deleted->id)->update([
        'updated_at' => $time,
        'deleted_at' => $time->copy()->addMinutes(2),
    ]);
    $post = TestPost::create(['tether_id' => '01HXYZ0001AABBCCDD0011POST', 'title' => 'Post']);
    DB::table('test_posts')->where('id', $post->id)->update(['updated_at' => $time->copy()->addMinute()]);

    $first = snapshotResult($generator, null, 1);
    $second = snapshotResult($generator, $first['new_sync_cursor'], 1);

    expect($first['snapshots'][0]['entity_id'])->toBe('01HXYZ0001AABBCCDD0011POST')
        ->and($first['has_more'])->toBeTrue()
        ->and($second['snapshots'][0]['entity_id'])->toBe('01HXYZ0001AABBCCDD0011DEL1')
        ->and($second['snapshots'][0]['operation'])->toBe('delete')
        ->and($second['has_more'])->toBeFalse();
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
