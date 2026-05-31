<?php

namespace Tether\Server\Http\Controllers;

use Composer\InstalledVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Tether\Core\Sync\PullRequest as PullDtoRequest;
use Tether\Core\Sync\PushRequest as PushDtoRequest;
use Tether\Server\PushSyncProcessor;
use Tether\Server\StateSnapshotGenerator;
use Tether\Server\Http\Requests\PullSyncRequest;
use Tether\Server\Http\Requests\PushSyncRequest;
use Tether\Server\Support\TetherLog;

class SyncController extends Controller
{
    public function __construct(
        private readonly PushSyncProcessor $pushes,
        private readonly StateSnapshotGenerator $snapshots,
    ) {}

    /**
     * Receive a batch of client mutations, apply them, and return results.
     */
    public function push(PushSyncRequest $request): JsonResponse
    {
        /** @var array{client_id: string, mutations?: list<array<string, mixed>>} $data */
        $data = $request->validated();
        $result = $this->pushes->process(PushDtoRequest::fromArray($data), $request);

        return $this->responseWithVersion($result->toArray());
    }

    /**
     * Return a state snapshot of all registered models changed since the client's last cursor.
     */
    public function pull(PullSyncRequest $request): JsonResponse
    {
        /** @var array{client_id: string, last_sync_cursor?: int|null, limit?: int|null} $data */
        $data = $request->validated();
        $pullRequest = PullDtoRequest::fromArray($data);

        return $this->responseWithVersion($this->snapshots->since($pullRequest, $request)->toArray());
    }

    /**
     * Wrap a JSON response with the server package version header.
     *
     * @param  array<string, mixed>  $data
     */
    private function responseWithVersion(array $data): JsonResponse
    {
        TetherLog::debug('Sending sync response', 3, [
            'keys' => array_keys($data),
        ]);

        $version = InstalledVersions::getPrettyVersion('tether/server') ?? 'dev';

        return response()->json($data)->header('X-Tether-Server-Version', $version);
    }
}
