<?php

namespace App\Console\Commands;

use App\Services\IuranNotificationService;
use Illuminate\Console\Command;

class SendIuranNotification extends Command
{
    protected $signature   = 'notifikasi:iuran';
    protected $description = 'Kirim notifikasi pengingat pembayaran iuran ke warga';

    public function handle(IuranNotificationService $service): void
    {
        $this->info('Memulai pengiriman notifikasi iuran...');
        $service->run();
        $this->info('Selesai.');
    }
}