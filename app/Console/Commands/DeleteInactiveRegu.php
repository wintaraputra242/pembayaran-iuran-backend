<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Regu;
use Carbon\Carbon;

class DeleteInactiveRegu extends Command
{
    protected $signature = 'regu:delete-inactive';
    protected $description = 'Hapus regu yang tidak aktif lebih dari 1 bulan';

    public function handle()
    {
        
        // batas waktu penghapusan (testing 1 menit)
        // $batas = Carbon::now()->subMinute();

        $batas = Carbon::now()->subMonth();

        // Ambil semua regu yang tidak aktif
        $semuaRegu = Regu::where('is_deleted', true)->get();

        // Pisahkan data yang sudah siap dihapus & yang belum
        $siapDihapus = $semuaRegu->filter(function ($item) use ($batas) {
            return $item->tanggal_nonaktif <= $batas;
        });

        $belumCukupWaktu = $semuaRegu->filter(function ($item) use ($batas) {
            return $item->tanggal_nonaktif > $batas;
        });

        // Jika tidak ada data sama sekali
        if ($semuaRegu->isEmpty()) {
            $this->info('Tidak ada data regu tidak aktif.');
            return;
        }

        // Jika ada data tidak aktif tetapi BELUM mencapai 1 bulan
        if ($siapDihapus->isEmpty()) {
            $this->info('Ada data regu tidak aktif, tetapi belum mencapai batas 1 bulan sehingga belum dihapus.');
            return;
        }

        // Hapus data yang sudah siap dihapus
        foreach ($siapDihapus as $item) {
            $item->delete();
        }

        $this->info(count($siapDihapus) . ' data regu tidak aktif lebih dari 1 bulan telah dihapus.');
    }

}
