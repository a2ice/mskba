<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /** Seed the application's base production-safe data. */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(SuperadminSeeder::class);
            $this->call(GeographySeeder::class);
            $this->call(FaqContentSeeder::class);
            $this->call(MoscowMetroSeeder::class);
        });
    }
}
