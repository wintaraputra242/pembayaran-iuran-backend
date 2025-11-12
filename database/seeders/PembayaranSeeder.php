<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PembayaranSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('pembayaran')->truncate();

        DB::table('pembayaran')->insert([
            [
                'id_warga' => 1,
                'id_informasi_iuran' => 1,
                'tanggal_bayar' => now()->toDateString(),
                'total_bayar' => 60000,
                'metode_bayar' => 'tunai',
                'status_bayar' => 'lunas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_warga' => 2,
                'id_informasi_iuran' => 1,
                'tanggal_bayar' => now()->toDateString(),
                'total_bayar' => 5000,
                'metode_bayar' => 'qris',
                'status_bayar' => 'lunas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
