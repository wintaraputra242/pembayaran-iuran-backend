<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
                'nik' => '3201010100010001',
                'id_informasi_iuran' => 1,
                'tanggal_bayar' => Carbon::now()->subDays(2),
                'total_bayar' => 50000,
                'metode_bayar' => 'tunai',
                'status_bayar' => 'lunas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
