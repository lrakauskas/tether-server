<?php

namespace Tether\Server;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tether\Core\Sync\PullRequest;
use Tether\Core\Sync\PullResult;
use Tether\Core\Sync\Snapshot;
use Tether\Server\Events\PullSyncCompleted;
use Tether\Server\Events\PullSyncStarted;
use Tether\Server\Support\TetherLog;

class StateSnapshotGenerator
{
    public function __construct(
        private readonly SyncRegistry $registry,
    ) {}

    /**
     * Return a state snapshot for all registered models, optionally filtered to
     * records changed since the given cursor (unix microseconds integer).
     *
     * When $limit is provided the response will contain at most that many snapshots.
     * If the underlying data set has more rows the response includes `has_more: true`
     * and the client should issue another pull using the returned `new_sync_cursor`.
     * Because upsert is idempotent, rows at the cursor boundary may be re-delivered
     * on subsequent pages - this is safe and expected.
     */
    public function since(PullRequest $pullRequest, Request $request): PullResult
    {
        $syncId = (string) Str::uuid();
        $startedAt = microtime(true);

        PullSyncStarted::dispatch($syncId, $pullRequest, $request, $startedAt);

        $snapshots    = [];
        $maxTimestamp = null;
        $hasMore      = false;

        $cursor = $pullRequest->cursor;
        $clientId = $pullRequest->clientId;
        $limit = $pullRequest->limit;
        $syncKey = config('tether-server.sync_key', 'tether_id');
        $cursorCarbon = $cursor !== null ? $this->fromMicros($cursor) : null;

        TetherLog::debug('Generating state snapshots', 1, [
            'client_id' => $clientId,
            'cursor' => $cursor,
            'limit' => $limit,
            'registered_models' => count($this->registry->getRegistrations()),
        ]);

        foreach ($this->registry->getRegistrations() as $modelClass => $registration) {
            // Stop collecting entirely once the limit is already satisfied.
            if ($limit !== null && count($snapshots) >= $limit) {
                $hasMore = true;
                break;
            }

            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass));

            $query = $modelClass::query();

            if ($usesSoftDeletes) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            if ($cursorCarbon !== null) {
                if ($usesSoftDeletes) {
                    $query->where(function ($q) use ($cursorCarbon): void {
                        $q->where('updated_at', '>', $cursorCarbon)
                            ->orWhere('deleted_at', '>', $cursorCarbon);
                    });
                } else {
                    $query->where('updated_at', '>', $cursorCarbon);
                }
            }
            TetherLog::debug('Built snapshot base query', 3, [
                'model_class' => $modelClass,
                'cursor' => $cursor,
                'query' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);

            if ($registration->scope !== null) {
                $query = ($registration->scope)($query, $clientId, $request);
            }

            TetherLog::debug('Built snapshot scoped query', 3, [
                'model_class' => $modelClass,
                'cursor' => $cursor,
                'query' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);

            // When paginating, limit the DB query to only what's needed plus one
            // extra row so we can detect whether more rows exist without loading
            // the full result set.
            if ($limit !== null) {
                $remaining = $limit - count($snapshots);
                $query->orderBy('updated_at')->limit($remaining + 1);
            }

            $pullSnapshotMapper = $registration->pullSnapshotMapper;

            foreach ($query->get() as $row) {
                // Stop mid-model if the limit is reached, signalling more data.
                if ($limit !== null && count($snapshots) >= $limit) {
                    $hasMore = true;
                    break 2;
                }

                $entityId = $row->getAttribute($syncKey);

                if ($entityId === null) {
                    continue;
                }

                $isTrashed = $usesSoftDeletes && $row->trashed();
                $operation = $isTrashed ? 'delete' : 'upsert';

                // Strip the server's primary key - it is server-local and meaningless on the client.
                $attributes = $row->getAttributes();
                unset($attributes[$row->getKeyName()]);

                $snapshot = new Snapshot(
                    entityId: $entityId,
                    model: class_basename($modelClass),
                    operation: $operation,
                    payload: $attributes,
                );

                if ($pullSnapshotMapper !== null) {
                    $snapshot = ($pullSnapshotMapper)($snapshot, $row);
                }

                $snapshots[] = $snapshot;

                // Track the maximum timestamp seen to advance the cursor.
                $rowMax = $row->updated_at;

                if ($usesSoftDeletes && $row->deleted_at !== null) {
                    if ($rowMax === null || $row->deleted_at->greaterThan($rowMax)) {
                        $rowMax = $row->deleted_at;
                    }
                }

                if ($rowMax !== null) {
                    if ($maxTimestamp === null || $rowMax->greaterThan($maxTimestamp)) {
                        $maxTimestamp = $rowMax;
                    }
                }
            }
        }

        $newCursor = $maxTimestamp !== null ? $this->toMicros($maxTimestamp) : $cursor;

        TetherLog::debug('Generated state snapshots', 1, [
            'snapshot_count' => count($snapshots),
            'new_sync_cursor' => $newCursor,
            'has_more' => $hasMore,
        ]);

        $result = new PullResult(
            snapshots: $snapshots,
            newSyncCursor: $newCursor,
            hasMore: $hasMore,
        );

        PullSyncCompleted::dispatch(
            syncId: $syncId,
            pullRequest: $pullRequest,
            result: $result,
            httpRequest: $request,
            startedAt: $startedAt,
            durationMs: round((microtime(true) - $startedAt) * 1000, 3),
        );

        return $result;
    }

    private function toMicros(\Carbon\CarbonInterface $dt): int
    {
        return $dt->getTimestamp() * 1_000_000 + $dt->microsecond;
    }

    private function fromMicros(int $micros): Carbon
    {
        return Carbon::createFromTimestamp(intdiv($micros, 1_000_000))
            ->addMicroseconds($micros % 1_000_000);
    }
}
