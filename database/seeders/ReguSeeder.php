<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReguSeeder extends Seeder
{
    public function run(): void
    {
        // id_user 2-7 = ketua_regu 1-6 (sesuai UserSeeder)
        $regu = [
            [
                'nama_regu'        => 'Regu 1',
                'status_keaktifan' => 'aktif',
                'id_user'          => 2,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nama_regu'        => 'Regu 2',
                'status_keaktifan' => 'aktif',
                'id_user'          => 3,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nama_regu'        => 'Regu 3',
                'status_keaktifan' => 'aktif',
                'id_user'          => 4,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nama_regu'        => 'Regu 4',
                'status_keaktifan' => 'aktif',
                'id_user'          => 5,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nama_regu'        => 'Regu 5',
                'status_keaktifan' => 'aktif',
                'id_user'          => 6,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'nama_regu'        => 'Regu 6',
                'status_keaktifan' => 'aktif',
                'id_user'          => 7,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ];

        DB::table('regu')->insert($regu);
    }
}