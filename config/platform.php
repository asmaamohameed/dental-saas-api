<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform administrator seed credentials
    |--------------------------------------------------------------------------
    |
    | Used only by AdminUserSeeder. Leave these empty in source control.
    | Set ADMIN_EMAIL and ADMIN_PASSWORD in the environment before seeding.
    |
    */

    'admin_name' => env('ADMIN_NAME', 'Platform Administrator'),

    'admin_email' => env('ADMIN_EMAIL'),

    'admin_password' => env('ADMIN_PASSWORD'),

];
