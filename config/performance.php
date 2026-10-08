<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request timing
    |--------------------------------------------------------------------------
    |
    | When enabled, API responses include a Server-Timing header and slow
    | requests / queries are written as warning logs (no bodies, ids, or
    | query bindings).
    |
    */

    'request_timing_enabled' => filter_var(env('REQUEST_TIMING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'slow_request_threshold_ms' => (int) env('SLOW_REQUEST_THRESHOLD_MS', 500),

    'slow_query_threshold_ms' => (int) env('SLOW_QUERY_THRESHOLD_MS', 500),

];
