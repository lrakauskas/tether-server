<?php

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Tests\Models\TestSyncablePost;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\Snapshot;
use Tether\Server\SyncRegistry;
use Tether\Server\Traits\Syncable;

it('auto-registers renamed mapper hooks from the Syncable trait', function () {
    new TestSyncablePost();

    $registry = app(SyncRegistry::class);

    expect($registry->isRegistered(TestSyncablePost::class))->toBeTrue();

    $pullMapper = $registry->getPullSnapshotMapper(TestSyncablePost::class);
    $pushMapper = $registry->getPushMutationMapper(TestSyncablePost::class);

    $snapshot = $pullMapper(
        new Snapshot('01HXYZ0001AABBCCDD0011AAB1', 'TestSyncablePost', 'upsert', [
            'title' => 'Server',
        ]),
        new TestSyncablePost(),
    );

    $mutation = $pushMapper(
        Mutation::fromArray([
            'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
            'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
            'model' => 'TestSyncablePost',
            'operation' => 'update',
            'payload' => [
                'title' => 'Client',
            ],
            'version' => 1,
            'timestamp' => 1,
        ]),
        Request::create('/tether/push', 'POST'),
    );

    expect($snapshot->payload['mapped_pull'])->toBeTrue()
        ->and($mutation->getPayload()['mapped_push'])->toBeTrue()
        ->and($registry->getConflictResolver(TestSyncablePost::class))->toBeCallable();
});

it('does not override explicit registry registrations', function () {
    $registry = app(SyncRegistry::class);
    $explicitPullMapper = fn(Snapshot $snapshot, Model $row): Snapshot => $snapshot->withPayload([
        'explicit' => true,
    ]);

    $registry->register(ExplicitlyRegisteredSyncablePost::class, pullSnapshotMapper: $explicitPullMapper);

    new ExplicitlyRegisteredSyncablePost();

    expect($registry->getPullSnapshotMapper(ExplicitlyRegisteredSyncablePost::class))->toBe($explicitPullMapper);
});

it('returns null syncable fields when fillable is empty', function () {
    expect((new UnfilteredSyncablePost())->getSyncableFields())->toBeNull();
});

it('returns explicit syncable fields', function () {
    expect((new ExplicitFieldsSyncablePost())->getSyncableFields())->toBe(['title']);
});

it('returns fillable minus excluded syncable fields', function () {
    expect((new ExceptFieldsSyncablePost())->getSyncableFields())->toBe(['title']);
});

it('returns null syncable fields when fillable has no exclusions', function () {
    expect((new FillableUnfilteredSyncablePost())->getSyncableFields())->toBeNull();
});

class ExplicitlyRegisteredSyncablePost extends Model
{
    use Syncable;

    protected $table = 'test_posts';
}

class UnfilteredSyncablePost extends Model
{
    use Syncable;

    protected $table = 'test_posts';
}

class ExplicitFieldsSyncablePost extends Model
{
    use Syncable;

    protected $table = 'test_posts';

    protected array $syncable = ['title'];
}

class ExceptFieldsSyncablePost extends Model
{
    use Syncable;

    protected $table = 'test_posts';

    protected $fillable = ['title', 'secret'];

    protected array $syncableExcept = ['secret'];
}

class FillableUnfilteredSyncablePost extends Model
{
    use Syncable;

    protected $table = 'test_posts';

    protected $fillable = ['title'];
}
