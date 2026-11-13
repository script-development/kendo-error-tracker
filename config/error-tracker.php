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
    | A kendo project token with the "Read & write" access level. Sent as a
    | Bearer token. Mint it from the project's API Tokens settings while signed
    | in as a dedicated account: the token can read the whole project as the
    | account that created it (see the README).
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
    | modes swallow every failure. `true`, `on`, `yes` and `1` turn sync on;
    | any other value, `off` and `no` included, leaves it off.
    |
     */

    'sync' => env('ERROR_TRACKER_SYNC', false),

    /*
    |--------------------------------------------------------------------------
    | Connect timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait while establishing the connection to the kendo host. Kept
    | short — this is fire-and-forget telemetry that must never block a request.
    | A value that is not above 0 and at most 30 falls back to the default.
    |
     */

    'connect_timeout' => env('ERROR_TRACKER_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Total seconds to wait for the POST to complete (connect + transfer). Bounds
    | the call so a hung kendo host cannot stall the caller. A value that is not
    | above 0 and at most 30 falls back to the default.
    |
     */

    'timeout' => env('ERROR_TRACKER_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Send messages
    |--------------------------------------------------------------------------
    |
    | When true (default) each report carries the exception's message, and
    | each cause's, path-normalized and scrubbed; a database exception's
    | message is replaced by its class and SQLSTATE. When false every message
    | is sent as "[REDACTED:message]". Turn it off when nothing may leave that
    | a scrubber cannot vouch for: a message is free text, and no pattern
    | matches a name or an address in it. `true`, `on`, `yes` and `1` keep it
    | on; any other value turns it off.
    |
     */

    'send_messages' => env('ERROR_TRACKER_SEND_MESSAGES', true),

    /*
    |--------------------------------------------------------------------------
    | Send user hash
    |--------------------------------------------------------------------------
    |
    | When true (default) a report from a request whose default guard holds a
    | signed-in user carries user_hash, an HMAC of the user's id, so kendo can
    | count affected users. The hash is personal data under the AVG: it links
    | one user's reports. When false the key is never sent and the tenant
    | resolver is never called. `true`, `on`, `yes` and `1` keep it on; any
    | other value turns it off.
    |
     */

    'send_user_hash' => env('ERROR_TRACKER_SEND_USER_HASH', true),

    /*
    |--------------------------------------------------------------------------
    | Queue connection
    |--------------------------------------------------------------------------
    |
    | The queue connection ReportErrorJob is dispatched on in async mode. Null
    | (default) or blank uses the app's default connection. A report raised
    | inside a database transaction is written with that transaction, so a
    | rollback deletes it. Name a queue connection whose database connection
    | is a second connection onto the same database, and the report survives
    | the rollback. The app defines both connections; see the README.
    |
     */

    'queue_connection' => env('ERROR_TRACKER_QUEUE_CONNECTION'),

];
