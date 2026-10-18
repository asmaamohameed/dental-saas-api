<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Expiry warning window (days)
    |--------------------------------------------------------------------------
    |
    | Active in-stock items with an expiry_date within this many clinic-local
    | days (inclusive) are treated as "expiring soon" for alerts and filters.
    |
    */

    'expiry_warning_days' => (int) env('INVENTORY_EXPIRY_WARNING_DAYS', 30),

];
