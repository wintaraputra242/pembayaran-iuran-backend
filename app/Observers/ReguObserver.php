<?php

namespace App\Observers;

use App\Models\Regu;

class ReguObserver
{
    /**
     * Dipanggil SEBELUM $regu->delete() dieksekusi.
     *
     * Karena anggota_regu.id_regu → regu pakai restrictOnDelete,
     * kita soft delete semua anggota aktif terlebih dahulu
     * agar constraint tidak error.
     */
    public function deleting(Regu $regu): void
    {
        // Soft delete semua anggota regu yang belum terhapus
        $regu->anggotaRegu()
             ->whereNull('deleted_at')
             ->each(fn($anggota) => $anggota->delete());
    }
 
    /**
     * Dipanggil SEBELUM restore() dieksekusi.
     *
     * Saat regu di-restore, restore juga anggota yang ikut
     * ter-soft delete bersamaan (berdasarkan waktu yang sama).
     */
    public function restoring(Regu $regu): void
    {
        // Restore anggota yang di-soft delete pada waktu yang sama
        // dengan waktu regu dihapus (toleransi 5 detik)
        $regu->anggotaRegu()
             ->withTrashed()
             ->whereBetween('deleted_at', [
                 $regu->deleted_at->subSeconds(5),
                 $regu->deleted_at->addSeconds(5),
             ])
             ->each(fn($anggota) => $anggota->restore());
    }
}

