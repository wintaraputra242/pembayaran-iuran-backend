<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WargaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('warga')->truncate();

        $wargas = [
            [
                'nik' => '3201010100010001',
                'nama_warga' => 'Budi Santoso',
                'alamat' => 'Jl. Melati No. 5',
                'no_hp' => '081234567890',
                'status_keaktifan' => 'aktif',
            ],
            [
                'nik' => '3201010100010002',
                'nama_warga' => 'Siti Aminah',
                'alamat' => 'Jl. Mawar No. 10',
                'no_hp' => '081234567891',
                'status_keaktifan' => 'aktif',
            ]
        ];

        foreach ($wargas as $w) {
            $w['created_at'] = now();
            $w['updated_at'] = now();
            DB::table('warga')->insert($w);
        }
    }
}
