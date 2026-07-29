<?php

namespace App\Helpers;

use App\Models\Warga;

class WargaHelper
{
  /**
   * Ambil warga yang wajib bayar iuran di bulan & tahun tertentu
   * - Warga aktif yang sudah bergabung sebelum/di bulan ini
   * - Warga nonaktif yang masih punya kewajiban di bulan sebelum nonaktif
   */
  public static function getWargaWajibBayar(
    int $bulan,
    string $tahun,
    array $excludeNik = [],
    ?int $reguId = null  // ← tambah parameter
  ): \Illuminate\Database\Eloquent\Collection {
    return Warga::whereNotIn('nik', $excludeNik)
      ->whereNull('deleted_at')
      // Filter regu kalau ketua_regu
      ->when($reguId, function ($q) use ($reguId) {
        $q->whereHas('anggotaRegu', function ($q2) use ($reguId) {
          $q2->where('id_regu', $reguId)
            ->whereNull('deleted_at')
            ->where('status_keaktifan', 'aktif');
        });
      })
      ->where(function ($q) use ($bulan, $tahun) {
        $q
          // Warga aktif yang sudah bergabung sebelum/di bulan ini
          ->where(function ($q1) use ($bulan, $tahun) {
            $q1->where('status_keaktifan', 'aktif')
              ->where(function ($q2) use ($bulan, $tahun) {
                $q2->where(function ($q3) use ($bulan, $tahun) {
                  $q3->whereYear('created_at', '<', $tahun)
                    ->orWhere(function ($q4) use ($bulan, $tahun) {
                      $q4->whereYear('created_at', $tahun)
                        ->whereMonth('created_at', '<=', $bulan);
                    });
                });
              });
          })
          // ATAU warga nonaktif yang bulan ini masih sebelum tanggal nonaktif
          ->orWhere(function ($q1) use ($bulan, $tahun) {
            $q1->where('status_keaktifan', 'tidak_aktif')
              ->whereNotNull('tanggal_nonaktif')
              ->where(function ($q2) use ($bulan, $tahun) {
                $q2->whereYear('tanggal_nonaktif', '>', $tahun)
                  ->orWhere(function ($q3) use ($bulan, $tahun) {
                    $q3->whereYear('tanggal_nonaktif', $tahun)
                      ->whereMonth('tanggal_nonaktif', '>=', $bulan);
                  });
              });
          });
      })
      ->get();
  }
}
