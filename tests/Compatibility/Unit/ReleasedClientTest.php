<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Models\TestPost;
use Tests\Models\TestPostWithSoftDeletes;
use Tether\Client\ClientSyncRegistry;
use Tether\Client\PendingSyncQueue;
use Tether\Client\SnapshotApplicator;
use Tether\Client\SyncEngine;
use Tether\Client\SyncHttpClient;
use Tether\Client\SyncStateStore;
use Tether\Core\Sync\PullRequest;
use Tether\Core\Sync\PullResult;
use Tether\Server\Events\PullSyncStarted;
use Tether\Server\SyncRegistry;

it('runs the tagged v1 client across overflow pages and resumes from persisted integer state', function () {
    foreach ([SyncEngine::class, SyncHttpClient::class, PullRequest::class, PullResult::class] as $class) {
        expect(str_replace('\\', '/', (new ReflectionClass($class))->getFileName()))->toContain('/tests/Fixtures/v1/');
    }

    config([
        'database.connections.legacy-client' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'tether-client.connection' => 'legacy-client',
        'tether-client.pull_page_size' => 2,
        'tether-client.sync_lock' => false,
    ]);
    $schema = Schema::connection('legacy-client');
    foreach (['posts', 'comments'] as $table) {
        $schema->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('tether_id')->unique();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('tether_sync_state', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->timestamps();
    });

    $registry = app(SyncRegistry::class);
    $registry->register(TestPost::class);
    $registry->register(TestPostWithSoftDeletes::class);
    $time = now()->subHour()->startOfSecond();
    for ($i = 1; $i <= 6; $i++) {
        $model = $i % 2 ? TestPost::class : TestPostWithSoftDeletes::class;
        $model::create([
            'tether_id' => sprintf('01HXYZ0001AABBCCDD00%08d', $i),
            'title' => "Row {$i}",
            'updated_at' => $time->copy()->addSeconds($i <= 3 ? 1 : ($i <= 5 ? 2 : 3)),
        ]);
    }

    $state = new SyncStateStore();
    $startCursor = (int) $time->getPreciseTimestamp(6);
    $state->set('last_sync_cursor', (string) $startCursor);
    $requests = [];
    $counts = [];
    $this->app['events']->listen(PullSyncStarted::class, function (PullSyncStarted $event): void {
        expect($event->httpRequest->hasHeader('X-Tether-Client-Version'))->toBeFalse();
    });

    Http::fake(function ($request) use (&$requests, &$counts, $state) {
        $body = $request->data();
        expect($body['last_sync_cursor'])->toBeInt()
            ->and($state->get('last_sync_cursor'))->toBe((string) $body['last_sync_cursor']);
        $requests[] = $body['last_sync_cursor'];
        expect(count($requests))->toBeLessThanOrEqual(4);

        // Feed the actual HTTP body to the server route, deliberately omitting
        // version headers: compatibility must not depend on version detection.
        $response = $this->postJson(route('tether.pull'), $body)->assertOk();
        $payload = $response->json();
        expect($payload['new_sync_cursor'])->toBeInt();
        $counts[] = count($payload['snapshots']);

        return Http::response($payload);
    });

    $makeEngine = static fn (): SyncEngine => new SyncEngine(
        queue: new PendingSyncQueue(),
        http: new SyncHttpClient('https://server.test/tether/push', 'https://server.test/tether/pull', 'legacy-client'),
        state: new SyncStateStore(),
        snapshots: new SnapshotApplicator('Tests\\Compatibility\\Models', 'tether_id', new ClientSyncRegistry()),
    );
    $result = $makeEngine()->pull();

    expect($result->pulled)->toBe(6)
        ->and($result->pullErrors)->toBe(0)
        ->and($counts)->toBe([3, 2, 1])
        ->and($requests)->toBe([$startCursor, $startCursor + 1_000_000, $startCursor + 2_000_000])
        ->and($state->get('last_sync_cursor'))->toBe((string) ($startCursor + 3_000_000))
        ->and(Tests\Compatibility\Models\TestPost::count())->toBe(3)
        ->and(Tests\Compatibility\Models\TestPostWithSoftDeletes::count())->toBe(3);

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0000000007',
        'title' => 'Later write',
        'updated_at' => $time->copy()->addSeconds(4),
    ]);
    expect($makeEngine()->pull()->pulled)->toBe(1)
        ->and($requests[3])->toBe($startCursor + 3_000_000)
        ->and($state->get('last_sync_cursor'))->toBe((string) ($startCursor + 4_000_000));
});
