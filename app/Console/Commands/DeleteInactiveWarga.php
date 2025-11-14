<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Warga;
use Carbon\Carbon;

class DeleteInactiveWarga extends Command
{
    protected $signature = 'warga:delete-inactive';
    protected $description = 'Hapus warga yang tidak aktif lebih dari 1 bulan';

    public function handle()
    {
        
        // batas waktu penghapusan (testing 1 menit)
        // $batas = Carbon::now()->subMinute();

        $batas = Carbon::now()->subMonth();

        // Ambil semua warga yang tidak aktif
        $semuaWarga = Warga::where('status_keaktifan', 'tidak_aktif')->get();

        // Pisahkan data yang sudah siap dihapus & yang belum
        $siapDihapus = $semuaWarga->filter(function ($item) use ($batas) {
            return $item->tanggal_nonaktif <= $batas;
        });

        $belumCukupWaktu = $semuaWarga->filter(function ($item) use ($batas) {
            return $item->tanggal_nonaktif > $batas;
        });

        // Jika tidak ada data sama sekali
        if ($semuaWarga->isEmpty()) {
            $this->info('Tidak ada data warga tidak aktif.');
            return;
        }

        // Jika ada data tidak aktif tetapi BELUM mencapai 1 bulan
        if ($siapDihapus->isEmpty()) {
            $this->info('Ada data warga tidak aktif, tetapi belum mencapai batas 1 bulan sehingga belum dihapus.');
            return;
        }

        // Hapus data yang sudah siap dihapus
        foreach ($siapDihapus as $item) {
            $item->delete();
        }

        $this->info(count($siapDihapus) . ' data warga tidak aktif lebih dari 1 bulan telah dihapus.');
    }

}
