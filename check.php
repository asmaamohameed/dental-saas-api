<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$lastMigrations = DB::table('migrations')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

foreach ($lastMigrations as $m) {
    echo $m->migration.' - batch '.$m->batch.PHP_EOL;
}

echo PHP_EOL.'Total users (any): '.DB::table('users')->count().PHP_EOL;
echo 'Total patients (any): '.DB::table('patients')->count().PHP_EOL;
