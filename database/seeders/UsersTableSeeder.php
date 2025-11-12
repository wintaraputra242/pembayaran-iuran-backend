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
            'name' => 'Admin Banjar',
            'email' => 'admin@banjar.test',
            'username' => 'admin',
            'role' => 'admin',
            'password' => Hash::make('password123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ketua Regu (contoh 2 ketua)
        DB::table('users')->insert([
            'name' => 'Ketua Regu 1',
            'email' => 'ketuaA@banjar.test',
            'username' => 'ketuaA',
            'role' => 'ketua_regu',
            'password' => Hash::make('ketua123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'name' => 'Ketua Regu 2',
            'email' => 'ketuaB@banjar.test',
            'username' => 'ketuaB',
            'role' => 'ketua_regu',
            'password' => Hash::make('ketua123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
