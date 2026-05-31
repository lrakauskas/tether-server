<?php

namespace Tether\Server;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tether\Core\Exceptions\EntityNotFoundException;
use Tether\Core\Exceptions\MutationValidationException;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\PushConflict;
use Tether\Core\Sync\PushRejection;
use Tether\Core\Sync\PushRequest;
use Tether\Core\Sync\PushResult;
use Tether\Core\Sync\MutationApplicator;
use Tether\Server\Events\PushMutationProcessed;
use Tether\Server\Events\PushSyncCompleted;
use Tether\Server\Events\PushSyncStarted;
use Tether\Server\Models\ServerMutationLog;
use Tether\Server\Support\TetherLog;

class PushSyncProcessor
{
    public function __construct(
        private readonly MutationApplicator $applicator,
        private readonly SyncRegistry $registry,
        private readonly ConflictDetector $conflictDetector,
    ) {}

    /**
     * Apply a validated push request payload.
     */
    public function process(PushRequest $pushRequest, Request $request): PushResult
    {
        $clientId = $pushRequest->clientId;
        $syncId = (string) Str::uuid();
        $startedAt = microtime(true);

        PushSyncStarted::dispatch($syncId, $pushRequest, $request, $startedAt);

        TetherLog::debug('Processing push request', 1, [
            'client_id' => $clientId,
            'mutation_count' => count($pushRequest->mutations),
        ]);

        $incomingIds = array_map(fn(Mutation $mutation): string => $mutation->getMutationId(), $pushRequest->mutations);
        $existingIds = ServerMutationLog::whereIn('mutation_id', $incomingIds)
            ->pluck('mutation_id')
            ->flip()
            ->all();

        $result = new PushResult();
        $seenInBatch = [];

        foreach ($pushRequest->mutations as $mutation) {
            $mutationId = $mutation->getMutationId();
            $mutationStartedAt = microtime(true);

            if (isset($existingIds[$mutationId]) || isset($seenInBatch[$mutationId])) {
                $duplicateSource = isset($existingIds[$mutationId]) ? 'persisted' : 'batch';

                TetherLog::debug('Duplicate push mutation detected', 2, [
                    'mutation_id' => $mutationId,
                    'duplicate_source' => $duplicateSource,
                ]);

                if (config('tether-server.strict_duplicates', false)) {
                    $rejection = new PushRejection($mutationId, 'duplicate', (object) []);
                    $result = $result->withRejected($rejection);
                    $this->dispatchMutationProcessed(
                        $syncId,
                        $clientId,
                        $mutation,
                        'rejected',
                        $request,
                        reason: 'duplicate',
                        context: [
                            'duplicate_source' => $duplicateSource,
                            'rejection' => $rejection->toArray(),
                        ],
                        startedAt: $mutationStartedAt,
                    );
                } else {
                    $result = $result->withApplied($mutationId);
                    $this->dispatchMutationProcessed(
                        $syncId,
                        $clientId,
                        $mutation,
                        'applied',
                        $request,
                        reason: 'duplicate',
                        context: [
                            'duplicate_source' => $duplicateSource,
                            'idempotent' => true,
                        ],
                        startedAt: $mutationStartedAt,
                    );
                }

                continue;
            }

            $processedMutation = $mutation;
            $serverStateBefore = null;

            try {
                $modelClass = $this->modelClass($mutation->getModel());
                $processedMutation = $this->applyPushMutationMapper($mutation, $modelClass, $request);
                $serverRecord = $this->serverRecord($processedMutation, $modelClass);
                $serverStateBefore = $serverRecord?->toArray();

                $resolution = $this->conflictDetector->evaluate(
                    mutation: $processedMutation,
                    modelClass: $modelClass,
                    serverRecord: $serverRecord,
                    request: $request,
                );

                if ($resolution !== null) {
                    if (! $resolution->shouldApply) {
                        $serverPayload = $serverRecord ? $serverRecord->toArray() : [];
                        $serverUpdatedAt = $serverRecord?->getAttribute('updated_at');
                        $conflict = new PushConflict($mutationId, 'conflict', [
                            'server_state' => $serverPayload,
                            'server_updated_at' => $serverUpdatedAt?->toIso8601String(),
                        ]);

                        $result = $result->withConflict($conflict);

                        TetherLog::debug('Push mutation rejected as conflict', 2, [
                            'mutation_id' => $mutationId,
                            'model' => $processedMutation->getModel(),
                            'entity_id' => $processedMutation->getEntityId(),
                        ]);

                        $this->dispatchMutationProcessed(
                            $syncId,
                            $clientId,
                            $mutation,
                            'conflict',
                            $request,
                            processedMutation: $processedMutation,
                            reason: 'conflict',
                            serverStateBefore: $serverStateBefore,
                            context: [
                                'conflict' => $conflict->toArray(),
                            ],
                            startedAt: $mutationStartedAt,
                        );

                        continue;
                    }

                    $processedMutation = $processedMutation->withPayload($resolution->payload);
                    TetherLog::debug('Push conflict resolver allowed mutation', 2, [
                        'mutation_id' => $mutationId,
                        'model' => $processedMutation->getModel(),
                        'entity_id' => $processedMutation->getEntityId(),
                    ]);
                }

                $this->applicator->apply($processedMutation);

                ServerMutationLog::create([
                    'mutation_id' => $processedMutation->getMutationId(),
                    'entity_id' => $processedMutation->getEntityId(),
                    'model' => $processedMutation->getModel(),
                    'operation' => $processedMutation->getOperation(),
                    'payload' => $processedMutation->getPayload(),
                    'version' => $processedMutation->getVersion(),
                    'timestamp' => $processedMutation->getTimestamp(),
                    'client_id' => $clientId,
                ]);

                $result = $result->withApplied($mutationId);
                $seenInBatch[$mutationId] = true;

                TetherLog::debug('Push mutation applied', 2, [
                    'mutation_id' => $mutationId,
                    'model' => $processedMutation->getModel(),
                    'entity_id' => $processedMutation->getEntityId(),
                    'operation' => $processedMutation->getOperation(),
                ]);

                $serverStateAfter = $this->serverRecord($processedMutation, $modelClass, includeCreates: true)?->toArray();

                $this->dispatchMutationProcessed(
                    $syncId,
                    $clientId,
                    $mutation,
                    'applied',
                    $request,
                    processedMutation: $processedMutation,
                    serverStateBefore: $serverStateBefore,
                    serverStateAfter: $serverStateAfter,
                    context: [
                        'conflict_resolver_applied' => $resolution?->shouldApply === true,
                    ],
                    startedAt: $mutationStartedAt,
                );
            } catch (MutationValidationException $e) {
                $rejection = new PushRejection($mutationId, 'validation_failed', [
                    'messages' => $e->messages,
                ]);
                $result = $result->withRejected($rejection);
                $this->dispatchMutationProcessed($syncId, $clientId, $mutation, 'rejected', $request, processedMutation: $processedMutation, reason: 'validation_failed', serverStateBefore: $serverStateBefore, context: [
                    'rejection' => $rejection->toArray(),
                ], startedAt: $mutationStartedAt);
            } catch (EntityNotFoundException $e) {
                $rejection = new PushRejection($mutationId, 'not_found', [
                    'model' => $e->model,
                    'entity_id' => $e->entityId,
                ]);
                $result = $result->withRejected($rejection);
                $this->dispatchMutationProcessed($syncId, $clientId, $mutation, 'rejected', $request, processedMutation: $processedMutation, reason: 'not_found', serverStateBefore: $serverStateBefore, context: [
                    'rejection' => $rejection->toArray(),
                ], startedAt: $mutationStartedAt);
            } catch (\Throwable $e) {
                report($e);
                $rejection = new PushRejection($mutationId, 'error', [
                    'message' => $e->getMessage(),
                ]);
                $result = $result->withRejected($rejection);
                $this->dispatchMutationProcessed($syncId, $clientId, $mutation, 'rejected', $request, processedMutation: $processedMutation, reason: 'error', serverStateBefore: $serverStateBefore, context: [
                    'rejection' => $rejection->toArray(),
                    'exception' => $e::class,
                ], startedAt: $mutationStartedAt);
            }
        }

        TetherLog::debug('Push request processed', 1, [
            'client_id' => $clientId,
            'applied_count' => count($result->applied),
            'rejected_count' => count($result->rejected),
            'conflict_count' => count($result->conflicts),
        ]);

        PushSyncCompleted::dispatch($syncId, $pushRequest, $result, $request, $startedAt, $this->durationMs($startedAt));

        return $result;
    }

    private function modelClass(string $model): string
    {
        return rtrim(config('tether-server.model_namespace', 'App\\Models'), '\\') . '\\' . class_basename($model);
    }

    private function applyPushMutationMapper(Mutation $mutation, string $modelClass, Request $request): Mutation
    {
        $pushMutationMapper = $this->registry->getPushMutationMapper($modelClass);

        if ($pushMutationMapper === null) {
            return $mutation;
        }

        $mutation = ($pushMutationMapper)($mutation, $request);

        TetherLog::debug('Push mutation mapper applied', 3, [
            'mutation_id' => $mutation->getMutationId(),
            'model_class' => $modelClass,
        ]);

        return $mutation;
    }

    private function serverRecord(Mutation $mutation, string $modelClass, bool $includeCreates = false): ?Model
    {
        if ((! $includeCreates && $mutation->getOperation()->value === 'create') || ! class_exists($modelClass)) {
            return null;
        }

        $syncKey = config('tether-server.sync_key', 'tether_id');
        $query = method_exists($modelClass, 'withTrashed')
            ? $modelClass::withTrashed()
            : $modelClass::query();

        return $query
            ->where($syncKey, $mutation->getEntityId())
            ->first();
    }

    /**
     * @param array<string, mixed>|null $serverStateBefore
     * @param array<string, mixed>|null $serverStateAfter
     * @param array<string, mixed> $context
     */
    private function dispatchMutationProcessed(
        string $syncId,
        string $clientId,
        Mutation $mutation,
        string $outcome,
        Request $request,
        ?Mutation $processedMutation = null,
        ?string $reason = null,
        ?array $serverStateBefore = null,
        ?array $serverStateAfter = null,
        array $context = [],
        float $startedAt = 0.0,
    ): void {
        PushMutationProcessed::dispatch(
            syncId: $syncId,
            clientId: $clientId,
            mutation: $mutation,
            outcome: $outcome,
            httpRequest: $request,
            processedMutation: $processedMutation,
            reason: $reason,
            serverStateBefore: $serverStateBefore,
            serverStateAfter: $serverStateAfter,
            context: $context,
            durationMs: $startedAt > 0 ? $this->durationMs($startedAt) : 0.0,
        );
    }

    private function durationMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 3);
    }
}
