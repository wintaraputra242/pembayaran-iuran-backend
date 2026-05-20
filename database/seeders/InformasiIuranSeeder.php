<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InformasiIuranSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('informasi_iuran')->insert([
            'judul_iuran'          => 'Iuran Bulanan 2024',
            'jenis_iuran'          => 'bulanan',
            'periode'              => '2024',
            'jumlah_iuran'         => 50000,
            'keterangan'           => 'Iuran wajib bulanan tahun 2024 untuk seluruh warga.',
            'nama_warga_meninggal' => null,
            'nik_penanggung_jawab' => null,
            'status_aktif'         => false,
            'created_at'           => '2024-01-01 00:00:00',
            'updated_at'           => '2024-01-01 00:00:00',
            'deleted_at'           => null,
        ]);
 
        DB::table('informasi_iuran')->insert([
            'judul_iuran'          => 'Iuran Bulanan 2025',
            'jenis_iuran'          => 'bulanan',
            'periode'              => '2025',
            'jumlah_iuran'         => 50000,
            'keterangan'           => 'Iuran wajib bulanan tahun 2025 untuk seluruh warga.',
            'nama_warga_meninggal' => null,
            'nik_penanggung_jawab' => null,
            'status_aktif'         => true,
            'created_at'           => '2025-01-01 00:00:00',
            'updated_at'           => '2025-01-01 00:00:00',
            'deleted_at'           => null,
        ]);
 
        DB::table('informasi_iuran')->insert([
            'judul_iuran'          => 'Iuran Kematian Bapak Soeharto',
            'jenis_iuran'          => 'kematian',
            'periode'              => null,
            'jumlah_iuran'         => 100000,
            'keterangan'           => 'Iuran solidaritas atas meninggalnya Bapak Soeharto warga RT.',
            'nama_warga_meninggal' => 'Soeharto',
            'nik_penanggung_jawab' => '3578010101900001',
            'status_aktif'         => false,
            'created_at'           => '2024-11-01 00:00:00',
            'updated_at'           => '2024-11-01 00:00:00',
            'deleted_at'           => '2024-12-01 00:00:00',
        ]);
 
        DB::table('informasi_iuran')->insert([
            'judul_iuran'          => 'Iuran Kematian Ibu Maryam',
            'jenis_iuran'          => 'kematian',
            'periode'              => null,
            'jumlah_iuran'         => 100000,
            'keterangan'           => 'Iuran solidaritas atas meninggalnya Ibu Maryam warga RT.',
            'nama_warga_meninggal' => 'Maryam',
            'nik_penanggung_jawab' => '3578010101900003',
            'status_aktif'         => true,
            'created_at'           => '2025-04-01 00:00:00',
            'updated_at'           => '2025-04-01 00:00:00',
            'deleted_at'           => null,
        ]);
    }
}