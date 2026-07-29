<?php

namespace App\Observers;

use App\Models\Warga;
use Illuminate\Validation\ValidationException;

class WargaObserver
{
    /**
     * Dipanggil SEBELUM $warga->delete() dieksekusi.
     *
     * Karena anggota_regu.nik → warga pakai restrictOnDelete,
     * kita perlu memastikan warga tidak punya keanggotaan aktif.
     *
     * Pilihan:
     *  - Throw exception jika masih aktif di regu (lebih aman, eksplisit)
     *  - Atau soft delete anggota_regu-nya dulu (seperti ReguObserver)
     *
     * Disini kita pakai throw exception agar penghapusan warga
     * selalu disengaja & terkontrol dari sisi bisnis.
     */
    public function deleting(Warga $warga): void
    {
        $anggotaAktif = $warga->anggotaRegu()
                              ->whereNull('deleted_at')
                              ->count();
 
        if ($anggotaAktif > 0) {
            throw ValidationException::withMessages([
                'warga' => "Warga [{$warga->nama_warga}] masih terdaftar sebagai anggota di {$anggotaAktif} regu aktif. Keluarkan dari regu terlebih dahulu sebelum menonaktifkan.",
            ]);
        }
 
        // Cek juga apakah warga masih jadi penanggung jawab iuran aktif
        $iuranAktif = $warga->iuranSebagaiPenanggungJawab()
                            ->whereNull('deleted_at')
                            ->count();
 
        if ($iuranAktif > 0) {
            throw ValidationException::withMessages([
                'warga' => "Warga [{$warga->nama_warga}] masih menjadi penanggung jawab di {$iuranAktif} iuran aktif. Ganti penanggung jawab terlebih dahulu.",
            ]);
        }
    }
 
    /**
     * Dipanggil SEBELUM restore() dieksekusi.
     *
     * Restore user yang terhubung ke warga ini (jika ada)
     * agar warga bisa login kembali.
     */
    public function restoring(Warga $warga): void
    {
        // Jika warga punya user yang ikut ter-soft delete,
        // restore user-nya juga
        if ($warga->user && $warga->user->trashed()) {
            $warga->user->restore();
        }
    }
}

