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
                'periode' => 'Jan-2025',
                'jumlah_iuran' => 5000,
                'keterangan' => 'Iuran bulanan reguler (per bulan)',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'jenis_iuran' => 'kematian',
                'periode' => null,
                'jumlah_iuran' => 100000,
                'keterangan' => 'Iuran kematian satu kali',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
