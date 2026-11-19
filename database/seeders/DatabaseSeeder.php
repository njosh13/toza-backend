<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database: the ARO catalogue always; demo firm,
     * users and matters outside production.
     */
    public function run(): void
    {
        $this->call(AroSeeder::class);

        if (! app()->isProduction() || config('toza.demo_seed')) {
            $this->call(DemoSeeder::class);
        }
    }
}
