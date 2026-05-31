<?php

use Tether\Core\Enums\OperationType;
use Tether\Core\Mutation\Mutation;
use Tether\Server\Models\ServerMutationLog;

it('uses configured table and connection names', function () {
    config([
        'tether-server.table' => 'custom_server_mutations',
        'tether-server.connection' => 'sqlite',
    ]);

    $log = new ServerMutationLog();

    expect($log->getTable())->toBe('custom_server_mutations')
        ->and($log->getConnectionName())->toBe('sqlite');
});

it('converts server log rows to mutation value objects', function () {
    $log = ServerMutationLog::create([
        'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model' => 'TestPost',
        'operation' => OperationType::Update,
        'payload' => [
            'title' => 'Updated',
        ],
        'version' => 2,
        'timestamp' => 123456,
        'client_id' => 'client-1',
        'applied_at' => now(),
    ]);

    $mutation = $log->toMutation();

    expect($mutation)->toBeInstanceOf(Mutation::class)
        ->and($mutation->getMutationId())->toBe('01HXYZ0000AABBCCDD0011AAB1')
        ->and($mutation->getEntityId())->toBe('01HXYZ0001AABBCCDD0011AAB1')
        ->and($mutation->getModel())->toBe('TestPost')
        ->and($mutation->getOperation())->toBe(OperationType::Update)
        ->and($mutation->getPayload())->toBe([
            'title' => 'Updated',
        ])
        ->and($mutation->getVersion())->toBe(2)
        ->and($mutation->getTimestamp())->toBe(123456);
});
