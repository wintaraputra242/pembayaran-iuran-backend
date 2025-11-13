<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InformasiIuranSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('informasi_iuran')->truncate();

        DB::table('informasi_iuran')->insert([
            [
                'jenis_iuran' => 'bulanan',
                'periode' => 'Nov-2025',
                'jumlah_iuran' => 50000,
                'keterangan' => 'Iuran bulanan November 2025',
                'status_aktif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'jenis_iuran' => 'kematian',
                'periode' => '2025',
                'jumlah_iuran' => 100000,
                'keterangan' => 'Iuran dana sosial kematian tahun 2025',
                'status_aktif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
