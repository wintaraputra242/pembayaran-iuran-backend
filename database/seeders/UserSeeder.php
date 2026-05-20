<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'       => 'Administrator',
                'username'   => 'admin',
                'password'   => Hash::make('password'),
                'role'       => 'admin',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Ketua Regu (6 orang)
            [
                'name'       => 'Budi Santoso',
                'username'   => 'ketua_regu1',
                'password'   => Hash::make('password'),
                'role'       => 'ketua_regu',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Slamet Raharjo',
                'username'   => 'ketua_regu2',
                'password'   => Hash::make('password'),
                'role'       => 'ketua_regu',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Hendra Gunawan',
                'username'   => 'ketua_regu3',
                'password'   => Hash::make('password'),
                'role'       => 'ketua_regu',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Agus Prasetyo',
                'username'   => 'ketua_regu4',
                'password'   => Hash::make('password'),
                'role'       => 'ketua_regu',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Doni Firmansyah',
                'username'   => 'ketua_regu5',
                'password'   => Hash::make('password'),
                'role'       => 'ketua_regu',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Rizky Hidayat',
                'username'   => 'ketua_regu6',
                'password'   => Hash::make('password'),
                'role'       => 'ketua_regu',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Warga (user login untuk warga)
            [
                'name'       => 'Dewi Rahayu',
                'username'   => 'warga1',
                'password'   => Hash::make('password'),
                'role'       => 'warga',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Siti Aminah',
                'username'   => 'warga2',
                'password'   => Hash::make('password'),
                'role'       => 'warga',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'       => 'Joko Susilo',
                'username'   => 'warga3',
                'password'   => Hash::make('password'),
                'role'       => 'warga',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('users')->insert($users);

        $passwords = [];

        $ketuaReguUsers = DB::table('users')
            ->where('role', 'ketua_regu')
            ->get();

        foreach ($ketuaReguUsers as $index => $user) {
            $reguId = $index + 1;
            $passwords['regu_' . $reguId] = [
                'username' => $user->username,
                'password' => 'password',
            ];
        }

        $passwordPath = 'credentials/passwords.json';
        Storage::put($passwordPath, json_encode($passwords, JSON_PRETTY_PRINT));
    }
}
