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
            ['nik' => '3201000000000001', 'nama_warga' => 'I Wayan S', 'alamat' => 'Banjar Trijata', 'no_hp' => '081234567890', 'id_regu' => 1, 'password' => Hash::make('warga123')],
            ['nik' => '3201000000000002', 'nama_warga' => 'Ni Nyoman T', 'alamat' => 'Banjar Trijata', 'no_hp' => '081234567891', 'id_regu' => 1, 'password' => Hash::make('warga123')],
            ['nik' => '3201000000000003', 'nama_warga' => 'I Gede P', 'alamat' => 'Banjar Trijata', 'no_hp' => '081234567892', 'id_regu' => 2, 'password' => Hash::make('warga123')],
        ];

        foreach ($wargas as $w) {
            $w['created_at'] = now();
            $w['updated_at'] = now();
            DB::table('warga')->insert($w);
        }
    }
}
