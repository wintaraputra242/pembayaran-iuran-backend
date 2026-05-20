<?php

namespace App\Observers;

use App\Models\InformasiIuran;
use Illuminate\Validation\ValidationException;

class InformasiIuranObserver
{
    /**
     * Dipanggil SEBELUM $iuran->delete() dieksekusi.
     *
     * Karena pembayaran.id_informasi_iuran → informasi_iuran
     * pakai restrictOnDelete, iuran tidak bisa dihapus jika
     * masih ada pembayaran yang terhubung.
     *
     * Kita throw exception agar penghapusan selalu eksplisit
     * dan tidak menghapus data keuangan secara tidak sengaja.
     */
    public function deleting(InformasiIuran $iuran): void
    {
        $jumlahPembayaran = $iuran->pembayaran()
                                  ->whereNull('deleted_at')
                                  ->count();
 
        if ($jumlahPembayaran > 0) {
            throw ValidationException::withMessages([
                'informasi_iuran' => "Iuran [{$iuran->judul_iuran}] masih memiliki {$jumlahPembayaran} data pembayaran. Tidak dapat dihapus.",
            ]);
        }
    }
}

