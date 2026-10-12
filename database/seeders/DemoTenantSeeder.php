<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Use DemoClinicSeeder. Kept so older docs/commands that reference this class still work.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoClinicSeeder::class);
    }
}
