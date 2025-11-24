<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\InformasiIuran;
use Carbon\Carbon;

class DeleteInactiveInformasiIuran extends Command
{
    protected $signature = 'informasi-iuran:delete-inactive';
    protected $description = 'Hapus informasi iuran yang tidak aktif lebih dari 1 bulan';

    public function handle()
    {
        
        // batas waktu penghapusan (testing 1 menit)
        // $batas = Carbon::now()->subMinute();

        $batas = Carbon::now()->subMonth();

        $semuaInformasiIuran = InformasiIuran::where('status_aktif', false)->get();

        $siapDihapus = $semuaInformasiIuran->filter(function ($item) use ($batas) {
            return $item->tanggal_nonaktif <= $batas;
        });

        $belumCukupWaktu = $semuaInformasiIuran->filter(function ($item) use ($batas) {
            return $item->tanggal_nonaktif > $batas;
        });

        if ($semuaInformasiIuran->isEmpty()) {
            $this->info('Tidak ada data informasi iuran tidak aktif.');
            return;
        }

        if ($siapDihapus->isEmpty()) {
            $this->info('Ada data informasi iuran tidak aktif, tetapi belum mencapai batas 1 bulan sehingga belum dihapus.');
            return;
        }

        foreach ($siapDihapus as $item) {
            $item->delete();
        }

        $this->info(count($siapDihapus) . ' data informasi iuran tidak aktif lebih dari 1 bulan telah dihapus.');
    }

}
