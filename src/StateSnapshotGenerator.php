<?php

namespace Tether\Server;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
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
     * Return globally ordered state snapshots after an integer microsecond cursor.
     * The limit is a target: returning the entire boundary timestamp group lets
     * pagination resume with a timestamp alone without losing tied records.
     */
    public function since(PullRequest $pullRequest, Request $request): PullResult
    {
        $syncId = (string) Str::uuid();
        $startedAt = microtime(true);

        PullSyncStarted::dispatch($syncId, $pullRequest, $request, $startedAt);

        $cursor = $pullRequest->cursor;
        $clientId = $pullRequest->clientId;
        $limit = $pullRequest->limit;
        $syncKey = config('tether-server.sync_key', 'tether_id');
        $cursorCarbon = $cursor !== null ? $this->fromMicros($cursor) : null;
        $queries = [];
        $timestamps = [];

        TetherLog::debug('Generating state snapshots', 1, [
            'client_id' => $clientId,
            'cursor' => $cursor,
            'limit' => $limit,
            'registered_models' => count($this->registry->getRegistrations()),
        ]);

        foreach ($this->registry->getRegistrations() as $modelClass => $registration) {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass));
            $query = $modelClass::query();

            if ($usesSoftDeletes) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            $model = $query->getModel();
            $grammar = $query->getQuery()->getGrammar();
            $updatedAt = $grammar->wrap($model->qualifyColumn('updated_at'));
            $deletedAt = $grammar->wrap($model->qualifyColumn('deleted_at'));
            $timestampSql = $usesSoftDeletes
                ? "CASE WHEN {$deletedAt} IS NOT NULL AND ({$updatedAt} IS NULL OR {$deletedAt} > {$updatedAt}) THEN {$deletedAt} ELSE {$updatedAt} END"
                : $updatedAt;

            $query->whereNotNull($model->qualifyColumn($syncKey));

            if ($cursorCarbon !== null) {
                // Bind strings in the model's storage format so fractional seconds
                // are not discarded by the connection's default date formatting.
                $query->whereRaw("{$timestampSql} > ?", [$cursorCarbon->format($model->getDateFormat())]);
            }

            if ($registration->scope !== null) {
                $query = ($registration->scope)($query, $clientId, $request);
            }

            $query->reorder()->orderByRaw($timestampSql)->orderBy($model->qualifyColumn($syncKey));

            TetherLog::debug('Built snapshot scoped query', 3, [
                'model_class' => $modelClass,
                'cursor' => $cursor,
                'query' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);

            $queries[] = compact('query', 'timestampSql', 'modelClass', 'registration', 'usesSoftDeletes');

            if ($limit !== null) {
                $columns = [$model->qualifyColumn('updated_at')];
                if ($usesSoftDeletes) {
                    $columns[] = $model->qualifyColumn('deleted_at');
                }

                foreach ((clone $query)->select($columns)->limit($limit)->get() as $row) {
                    $timestamp = $this->rowTimestamp($row, $usesSoftDeletes);
                    if ($timestamp !== null) {
                        $timestamps[] = $timestamp;
                    }
                }
            }
        }

        // The first N rows of each model contain the global Nth timestamp.
        // Fetch the whole group at that boundary, even when it exceeds N.
        sort($timestamps, SORT_NUMERIC);
        $boundary = $limit !== null && $timestamps !== [] ? $timestamps[min($limit, count($timestamps)) - 1] : null;
        $candidates = [];

        foreach ($queries as $definition) {
            if ($limit !== null && $boundary === null) {
                continue;
            }

            $query = clone $definition['query'];
            if ($boundary !== null) {
                $boundaryValue = $this->fromMicros($boundary)->format($query->getModel()->getDateFormat());
                $query->whereRaw("{$definition['timestampSql']} <= ?", [$boundaryValue]);
            }

            foreach ($query->get() as $row) {
                $candidates[] = [
                    'timestamp' => $this->rowTimestamp($row, $definition['usesSoftDeletes']),
                    'model' => $definition['modelClass'],
                    'entity_id' => (string) $row->getAttribute($syncKey),
                    'row' => $row,
                    'soft_deletes' => $definition['usesSoftDeletes'],
                    'mapper' => $definition['registration']->pullSnapshotMapper,
                ];
            }
        }

        usort($candidates, static fn (array $a, array $b): int =>
            ($a['timestamp'] <=> $b['timestamp'])
            ?: strcmp($a['model'], $b['model'])
            ?: strcmp($a['entity_id'], $b['entity_id']));

        $snapshots = [];
        $newCursor = $cursor;

        foreach ($candidates as $candidate) {
            $row = $candidate['row'];
            $attributes = $row->getAttributes();
            unset($attributes[$row->getKeyName()]);

            $snapshot = new Snapshot(
                entityId: $candidate['entity_id'],
                model: class_basename($candidate['model']),
                operation: $candidate['soft_deletes'] && method_exists($row, 'trashed') && $row->trashed() ? 'delete' : 'upsert',
                payload: $attributes,
            );

            if ($candidate['mapper'] !== null) {
                $snapshot = ($candidate['mapper'])($snapshot, $row);
            }

            $snapshots[] = $snapshot;
            if ($candidate['timestamp'] !== null) {
                $newCursor = $candidate['timestamp'];
            }
        }

        $hasMore = false;
        if ($limit !== null && $newCursor !== null) {
            foreach ($queries as $definition) {
                $query = clone $definition['query'];
                $nextValue = $this->fromMicros($newCursor)->format($query->getModel()->getDateFormat());
                if ($query->whereRaw("{$definition['timestampSql']} > ?", [$nextValue])->exists()) {
                    $hasMore = true;
                    break;
                }
            }
        }

        TetherLog::debug('Generated state snapshots', 1, [
            'snapshot_count' => count($snapshots),
            'new_sync_cursor' => $newCursor,
            'has_more' => $hasMore,
        ]);

        $result = new PullResult(snapshots: $snapshots, newSyncCursor: $newCursor, hasMore: $hasMore);

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

    private function rowTimestamp(Model $row, bool $usesSoftDeletes): ?int
    {
        $timestamp = $row->getAttribute('updated_at');
        $deletedAt = $usesSoftDeletes ? $row->getAttribute('deleted_at') : null;
        if ($deletedAt !== null && ($timestamp === null || $deletedAt->greaterThan($timestamp))) {
            $timestamp = $deletedAt;
        }

        return $timestamp !== null ? $this->toMicros($timestamp) : null;
    }

    private function toMicros(CarbonInterface $dt): int
    {
        return $dt->getTimestamp() * 1_000_000 + $dt->microsecond;
    }

    private function fromMicros(int $micros): Carbon
    {
        return Carbon::createFromTimestamp(intdiv($micros, 1_000_000))
            ->addMicroseconds($micros % 1_000_000);
    }
}
