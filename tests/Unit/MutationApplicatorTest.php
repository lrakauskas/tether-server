<?php

use Tests\Models\TestPost;
use Tether\Core\Enums\OperationType;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\MutationApplicator;

// ── Create ────────────────────────────────────────────────────────────────────

it('creates a model record from a create mutation', function () {
    $applicator = new MutationApplicator('Tests\\Models');

    $mutation = new Mutation(
        mutationId: '01HXYZ0000AABBCCDD0011AABB',
        entityId: '01HXYZ0001AABBCCDD0011AABB',
        model: 'TestPost',
        operation: OperationType::Create,
        payload: [
            'title' => 'Hello',
            'body' => 'World',
        ],
        version: 1,
        timestamp: 1000000,
    );

    $applicator->apply($mutation);

    $post = TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AABB')->first();

    expect($post)->not->toBeNull()
        ->and($post->title)->toBe('Hello')
        ->and($post->body)->toBe('World');
});

it('does not duplicate a record when a create mutation is applied twice', function () {
    $applicator = new MutationApplicator('Tests\\Models');

    $mutation = new Mutation(
        mutationId: '01HXYZ0000AABBCCDD0011AABB',
        entityId: '01HXYZ0001AABBCCDD0011AABB',
        model: 'TestPost',
        operation: OperationType::Create,
        payload: [
            'title' => 'Hello',
            'body' => null,
        ],
        version: 1,
        timestamp: 1000000,
    );

    $applicator->apply($mutation);
    $applicator->apply($mutation);

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AABB')->count())->toBe(1);
});

// ── Update ────────────────────────────────────────────────────────────────────

it('updates a model record from an update mutation', function () {
    $applicator = new MutationApplicator('Tests\\Models');

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AABB',
        'title' => 'Old',
        'body' => null,
    ]);

    $mutation = new Mutation(
        mutationId: '01HXYZ0000AABBCCDD0011AABB',
        entityId: '01HXYZ0001AABBCCDD0011AABB',
        model: 'TestPost',
        operation: OperationType::Update,
        payload: [
            'title' => 'Updated',
        ],
        version: 2,
        timestamp: 1000001,
    );

    $applicator->apply($mutation);

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AABB')->value('title'))->toBe('Updated');
});

// ── Delete ────────────────────────────────────────────────────────────────────

it('deletes a model record from a delete mutation', function () {
    $applicator = new MutationApplicator('Tests\\Models');

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AABB',
        'title' => 'To Delete',
        'body' => null,
    ]);

    $mutation = new Mutation(
        mutationId: '01HXYZ0000AABBCCDD0011AABB',
        entityId: '01HXYZ0001AABBCCDD0011AABB',
        model: 'TestPost',
        operation: OperationType::Delete,
        payload: [],
        version: 3,
        timestamp: 1000002,
    );

    $applicator->apply($mutation);

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AABB')->exists())->toBeFalse();
});

// ── Model resolution ──────────────────────────────────────────────────────────

it('throws a RuntimeException when the model class does not exist', function () {
    $applicator = new MutationApplicator('Tests\\Models');

    $mutation = new Mutation(
        mutationId: '01HXYZ0000AABBCCDD0011AABB',
        entityId: '01HXYZ0001AABBCCDD0011AABB',
        model: 'NonExistentModel',
        operation: OperationType::Create,
        payload: [],
        version: 1,
        timestamp: 1000000,
    );

    expect(fn() => $applicator->apply($mutation))->toThrow(\RuntimeException::class);
});

it('supports a custom sync key column', function () {
    $applicator = new MutationApplicator('Tests\\Models', 'tether_id');

    $mutation = new Mutation(
        mutationId: '01HXYZ0000AABBCCDD0011AABB',
        entityId: '01HXYZ0001AABBCCDD0011AABB',
        model: 'TestPost',
        operation: OperationType::Create,
        payload: [
            'title' => 'Custom Key',
            'body' => null,
        ],
        version: 1,
        timestamp: 1000000,
    );

    $applicator->apply($mutation);

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AABB')->exists())->toBeTrue();
});
