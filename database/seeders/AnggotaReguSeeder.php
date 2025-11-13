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
            [
                'id_regu' => 1,
                'nik' => '3201010100010001',
                'status_keaktifan' => 'aktif',
                'is_leader' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_regu' => 2, 
                'nik' => '3201010100010002', 
                'status_keaktifan' => 'aktif',
                'is_leader' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
