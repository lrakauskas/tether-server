<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection Tether Server uses to store applied mutations.
    | Set to null to use your application's default connection.
    |
    */
    'connection' => env('TETHER_SERVER_DB_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Server Mutations Table Name
    |--------------------------------------------------------------------------
    |
    | The name of the table that stores all server-side applied mutations.
    | Pull cursors are integer microsecond timestamps derived from syncable
    | model updated_at / deleted_at values, not this table's ID.
    |
    */
    'table' => env('TETHER_SERVER_TABLE', 'tether_server_mutations'),

    /*
    |--------------------------------------------------------------------------
    | Model Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace prefix used when resolving model classes from mutation
    | payloads. For example, a mutation with model "Post" will resolve to
    | "App\Models\Post" by default.
    |
    */
    'model_namespace' => env('TETHER_MODEL_NAMESPACE', 'App\\Models'),

    /*
    |--------------------------------------------------------------------------
    | Sync Key Column
    |--------------------------------------------------------------------------
    |
    | The column on server-side models that holds the client-generated ULID
    | used as the sync identity (entity_id). Must match the column populated
    | by the client's Syncable trait.
    |
    */
    'sync_key' => env('TETHER_SYNC_KEY', 'tether_id'),

    /*
    |--------------------------------------------------------------------------
    | Route Registration
    |--------------------------------------------------------------------------
    |
    | Set to false to disable automatic route registration. When disabled,
    | you must register the routes manually pointing at SyncController.
    |
    */
    'register_routes' => env('TETHER_REGISTER_ROUTES', true),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix applied to all Tether sync endpoints.
    | POST /{prefix}/push and POST /{prefix}/pull.
    |
    */
    'route_prefix' => env('TETHER_ROUTE_PREFIX', 'tether'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the Tether sync endpoints. Override this in your
    | published config to add authentication, throttling, or other middleware.
    |
    */
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Strict Duplicates
    |--------------------------------------------------------------------------
    |
    | Controls how the push endpoint handles a mutation_id that has already
    | been applied (i.e. a retried or duplicate push).
    |
    | false (default) - treat as idempotent success: add to `applied` without
    |                   re-applying. Safe for retry-heavy clients.
    | true            - reject with reason "duplicate": useful when you want
    |                   to surface unexpected retries as an explicit error.
    |
    */
    'strict_duplicates' => env('TETHER_STRICT_DUPLICATES', false),

    /*
    |--------------------------------------------------------------------------
    | Debug Level
    |--------------------------------------------------------------------------
    |
    | Controls Tether's package-level debug logging.
    | 0 = off, 1 = lifecycle summaries, 2 = decisions/outcomes,
    | 3 = verbose diagnostics.
    |
    | All Tether debug log messages are prefixed with [TETHER].
    |
    */
    'debug_level' => env('TETHER_DEBUG_LEVEL', 0),
];
