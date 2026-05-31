<?php

use Tests\Models\TestPost;
use Tether\Server\SyncRegistry;

it('returns null callbacks for unregistered models', function () {
    $registry = new SyncRegistry();

    expect($registry->getScope(TestPost::class))->toBeNull()
        ->and($registry->getPullSnapshotMapper(TestPost::class))->toBeNull()
        ->and($registry->getPushMutationMapper(TestPost::class))->toBeNull()
        ->and($registry->getConflictResolver(TestPost::class))->toBeNull();
});

it('stores registrations and reports registered models', function () {
    $scope = fn($query) => $query;
    $pull = fn($snapshot) => $snapshot;
    $push = fn($mutation) => $mutation;
    $resolver = fn() => null;

    $registry = new SyncRegistry();
    $registry->register(TestPost::class, $scope, $pull, $push, $resolver);

    expect($registry->isRegistered(TestPost::class))->toBeTrue()
        ->and($registry->getRegistrations())->toHaveKey(TestPost::class)
        ->and($registry->getScope(TestPost::class))->toBe($scope)
        ->and($registry->getPullSnapshotMapper(TestPost::class))->toBe($pull)
        ->and($registry->getPushMutationMapper(TestPost::class))->toBe($push)
        ->and($registry->getConflictResolver(TestPost::class))->toBe($resolver);
});
