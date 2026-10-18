<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic wall-clock timezone
    |--------------------------------------------------------------------------
    |
    | Working hours (open/close) are stored as local HH:mm strings. Instants
    | such as appointment scheduled_at are converted to this timezone before
    | comparing against those hours. Application timezone (config app.timezone)
    | remains UTC for storage.
    |
    */

    'timezone' => env('CLINIC_TIMEZONE', 'Africa/Cairo'),

];
