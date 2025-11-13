<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReguSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('regu')->truncate();

        DB::table('regu')->insert([
            ['nama_regu' => 'Regu A', 'created_at' => now(), 'updated_at' => now()],
            ['nama_regu' => 'Regu B', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
