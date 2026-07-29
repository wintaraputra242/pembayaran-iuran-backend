<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AnggotaReguSeeder extends Seeder
{
    public function run(): void
    {
        // Mapping regu_id => [ketua_nik, anggota_nik[]]
        // Regu 1 (id=1) ketua: Budi Santoso       (3578010101800001)
        // Regu 2 (id=2) ketua: Slamet Raharjo      (3578010101800002)
        // Regu 3 (id=3) ketua: Hendra Gunawan      (3578010101800003)
        // Regu 4 (id=4) ketua: Agus Prasetyo       (3578010101800004)
        // Regu 5 (id=5) ketua: Doni Firmansyah     (3578010101800005)
        // Regu 6 (id=6) ketua: Rizky Hidayat       (3578010101800006)

        $anggota = [

            // ── REGU 1 ────────────────────────────────────────────────
            [
                'id_regu'          => 1,
                'nik'              => '3578010101800001', // Budi Santoso (ketua)
                'status_keaktifan' => 'aktif',
                'is_leader'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 1,
                'nik'              => '3578010101900001', // Dewi Rahayu
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 1,
                'nik'              => '3578010101900002', // Siti Aminah
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],

            // ── REGU 2 ────────────────────────────────────────────────
            [
                'id_regu'          => 2,
                'nik'              => '3578010101800002', // Slamet Raharjo (ketua)
                'status_keaktifan' => 'aktif',
                'is_leader'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 2,
                'nik'              => '3578010101900003', // Joko Susilo
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 2,
                'nik'              => '3578010101900004', // Wahyu Nugroho
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],

            // ── REGU 3 ────────────────────────────────────────────────
            [
                'id_regu'          => 3,
                'nik'              => '3578010101800003', // Hendra Gunawan (ketua)
                'status_keaktifan' => 'aktif',
                'is_leader'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 3,
                'nik'              => '3578010101900005', // Eko Widodo
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 3,
                'nik'              => '3578010101900006', // Bambang Sutrisno
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],

            // ── REGU 4 ────────────────────────────────────────────────
            [
                'id_regu'          => 4,
                'nik'              => '3578010101800004', // Agus Prasetyo (ketua)
                'status_keaktifan' => 'aktif',
                'is_leader'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 4,
                'nik'              => '3578010101900007', // Yuli Astuti
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 4,
                'nik'              => '3578010101900008', // Ratna Sari
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 4,
                'nik'              => '3578010101900009', // Fajar Setiawan
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],

            // ── REGU 5 ────────────────────────────────────────────────
            [
                'id_regu'          => 5,
                'nik'              => '3578010101800005', // Doni Firmansyah (ketua)
                'status_keaktifan' => 'aktif',
                'is_leader'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 5,
                'nik'              => '3578010101900010', // Indra Kusuma
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 5,
                'nik'              => '3578010101900011', // Maya Puspita
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 5,
                'nik'              => '3578010101900012', // Tono Hartono
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],

            // ── REGU 6 ────────────────────────────────────────────────
            [
                'id_regu'          => 6,
                'nik'              => '3578010101800006', // Rizky Hidayat (ketua)
                'status_keaktifan' => 'aktif',
                'is_leader'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 6,
                'nik'              => '3578010101900013', // Rina Marlina
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 6,
                'nik'              => '3578010101900014', // Surya Admaja
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'id_regu'          => 6,
                'nik'              => '3578010101900015', // Putri Handayani
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ];

        // Warga belum masuk regu (sisa):
        // 3578010101900016 - Guntur Wibowo
        // 3578010101900017 - Lestari Wulandari
        // 3578010101900018 - Andri Kurniawan

        DB::table('anggota_regu')->insert($anggota);
    }
}