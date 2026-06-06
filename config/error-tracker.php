<?php

declare(strict_types = 1);

return [
    /*
    |--------------------------------------------------------------------------
    | Kendo URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the kendo instance that receives error events. The client
    | POSTs to "{kendo_url}/api/projects/{project}/error-events".
    |
     */

    'kendo_url' => env('ERROR_TRACKER_KENDO_URL'),

    /*
    |--------------------------------------------------------------------------
    | Project
    |--------------------------------------------------------------------------
    |
    | The kendo project id that owns the error events. This is the {project}
    | route-key — kendo binds {project} by id (no route-key override on the
    | Project model). The project token below is bound to this project.
    |
     */

    'project' => env('ERROR_TRACKER_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    |
    | A kendo project token carrying the "error-events:write" ability. Sent as
    | a Bearer token. Mint it from the kendo project's API-token settings.
    |
     */

    'token' => env('ERROR_TRACKER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The deploy environment label sent with each event (e.g. "production").
    | Defaults to the app environment.
    |
     */

    'environment' => env('ERROR_TRACKER_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | Optional release identifier (e.g. a git sha or version tag) sent with
    | each event. Null when unset.
    |
     */

    'release' => env('ERROR_TRACKER_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    |
    | When false (default) reports dispatch to the queue via ReportErrorJob.
    | When true the HTTP POST happens inline in the request that reported. Both
    | modes swallow every failure.
    |
     */

    'sync' => env('ERROR_TRACKER_SYNC', false),

];
