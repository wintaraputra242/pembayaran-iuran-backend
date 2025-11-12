<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AnggotaReguSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('anggota_regu')->truncate();

        DB::table('anggota_regu')->insert([
            ['id_regu' => 1, 'id_warga' => 1, 'status_keaktifan' => 'aktif', 'created_at' => now(), 'updated_at' => now()],
            ['id_regu' => 1, 'id_warga' => 2, 'status_keaktifan' => 'aktif', 'created_at' => now(), 'updated_at' => now()],
            ['id_regu' => 2, 'id_warga' => 3, 'status_keaktifan' => 'aktif', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
