<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Hapus data lama (opsional)
        // DB::table('users')->truncate();

        // Admin
        DB::table('users')->insert([
            'name' => 'Admin Sistem',
            'email' => 'admin@example.com',
            'username' => 'admin',
            'role' => 'admin',
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ketua Regu (contoh 2 ketua)
        DB::table('users')->insert([
            'name' => 'Ketua Regu A',
            'email' => 'ketuaA@example.com',
            'username' => 'ketuaA',
            'role' => 'ketua_regu',
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // DB::table('users')->insert([
        //     'name' => 'Budi Santoso',
        //     'email' => 'budi@example.com',
        //     'username' => 'budi',
        //     'role' => 'warga',
        //     'nik' => '3201010100010001',
        //     'password' => Hash::make('budi123'),
        //     'remember_token' => Str::random(10),
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);
    }
}
