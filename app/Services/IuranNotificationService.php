<?php

namespace App\Services;

use App\Helpers\WargaHelper;
use App\Models\InformasiIuran;
use App\Models\NotificationLog;
use App\Models\Pembayaran;
use App\Models\UserDevice;
use App\Models\Warga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class IuranNotificationService
{
    public function __construct(
        protected FonnteService  $fonnte,
        protected FirebaseService $firebase,
    ) {}

    // -------------------------------------------------------
    // ENTRY POINT — dipanggil Scheduler
    // -------------------------------------------------------
    public function run(): void
    {
        $this->handleBulanan();
        $this->handleKematian();
    }

    // -------------------------------------------------------
    // NOTIF GRUP — dipanggil saat iuran baru dibuat
    // -------------------------------------------------------
    public function notifikasiIuranBaru(InformasiIuran $iuran): void
    {
        $nominal = number_format($iuran->jumlah_iuran, 0, ',', '.');

        if ($iuran->jenis_iuran === 'bulanan') {
            $pesan = <<<MSG
            📢 *Informasi Iuran Baru*

            Telah dibuka iuran bulanan tahun *{$iuran->periode}*.

            📋 *Detail:*
            • Judul   : {$iuran->judul_iuran}
            • Nominal : Rp {$nominal}
            • Periode : {$iuran->periode}

            {$iuran->keterangan}

            Mohon segera melakukan pembayaran melalui aplikasi.
            Terima kasih. 🙏
            _Pengurus Banjar_
            MSG;
        } else {
            $almarhum = $iuran->nama_warga_meninggal ?? '-';
            $pesan    = <<<MSG
            📢 *Informasi Iuran Kematian*

            Telah dibuka iuran solidaritas atas meninggalnya *{$almarhum}*.

            📋 *Detail:*
            • Judul   : {$iuran->judul_iuran}
            • Nominal : Rp {$nominal}

            {$iuran->keterangan}

            Mohon segera melakukan pembayaran melalui aplikasi.
            Terima kasih. 🙏
            _Pengurus Banjar_
            MSG;
        }

        // Kirim ke grup WA
        $this->fonnte->sendGroup($pesan);

        // Kirim push notification Firebase ke semua user aktif
        $this->notifikasiFirebaseAllWarga(
            title: 'Informasi Iuran Baru',
            body: "Telah dibuka {$iuran->judul_iuran}. Segera lakukan pembayaran.",
        );

        Log::info('Notifikasi iuran baru dikirim', ['id_iuran' => $iuran->id]);
    }

    // -------------------------------------------------------
    // IURAN BULANAN
    // -------------------------------------------------------
    private function handleBulanan(): void
    {
        $today   = Carbon::today();
        $bulan   = (int) $today->format('n');
        $tahun   = $today->format('Y');
        $tanggal = (int) $today->format('j');

        $type = match (true) {
            $tanggal === 1                   => 'bulanan_awal',
            $tanggal === 10                  => 'bulanan_tengah',
            $tanggal === $today->daysInMonth => 'bulanan_akhir',
            default                          => null,
        };

        if (!$type) return;

        $iuranList = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($iuranList as $iuran) {
            $sudahBayarNik = Pembayaran::where('id_informasi_iuran', $iuran->id)
                ->where('status_bayar', 'approved')
                ->whereJsonContains('bulan', (string) $bulan)
                ->whereNull('deleted_at')
                ->pluck('nik')
                ->toArray();

            $wargaBelumBayar = WargaHelper::getWargaWajibBayar($bulan, $tahun, $sudahBayarNik)
                ->filter(fn($w) => $w->no_hp || $w->user?->devices->isNotEmpty())
                ->load('user.devices');

            $periodeKey = $tahun . '-' . str_pad($bulan, 2, '0', STR_PAD_LEFT);

            foreach ($wargaBelumBayar as $warga) {
                $message = $this->pesanBulanan(
                    $warga->nama_warga,
                    $iuran,
                    $bulan,
                    $tahun,
                    $type
                );

                $this->kirimNotifikasi(
                    warga: $warga,
                    iuran: $iuran,
                    type: $type,
                    periode: $periodeKey,
                    title: 'Pengingat Iuran Bulanan',
                    message: $message,
                );
            }
        }
    }

    // -------------------------------------------------------
    // IURAN KEMATIAN
    // -------------------------------------------------------
    private function handleKematian(): void
    {
        $today = Carbon::today();

        $iuranList = InformasiIuran::where('jenis_iuran', 'kematian')
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($iuranList as $iuran) {
            $selisihHari = Carbon::parse($iuran->created_at)->diffInDays($today);

            $type = match (true) {
                $selisihHari === 1  => 'kematian_h1',
                $selisihHari === 7  => 'kematian_h7',
                $selisihHari === 30 => 'kematian_h30',
                default             => null,
            };

            if (!$type) continue;

            $sudahBayarNik = Pembayaran::where('id_informasi_iuran', $iuran->id)
                ->where('status_bayar', 'approved')
                ->whereNull('deleted_at')
                ->pluck('nik')
                ->toArray();

            $wargaBelumBayar = Warga::whereNotIn('nik', $sudahBayarNik)
                ->where(function ($q) {
                    $q->where('status_keaktifan', 'aktif')
                        ->orWhere(function ($q2) {
                            // Warga nonaktif yang tanggal nonaktifnya setelah iuran kematian dibuat
                            $q2->where('status_keaktifan', 'tidak_aktif')
                                ->whereNotNull('tanggal_nonaktif');
                        });
                })
                ->whereNull('deleted_at')
                ->with('user.devices')
                ->get();;

            foreach ($wargaBelumBayar as $warga) {
                $message = $this->pesanKematian(
                    $warga->nama_warga,
                    $iuran,
                    $selisihHari,
                    $type
                );

                $this->kirimNotifikasi(
                    warga: $warga,
                    iuran: $iuran,
                    type: $type,
                    periode: null,
                    title: 'Pengingat Iuran Kematian',
                    message: $message,
                );
            }
        }
    }

    // -------------------------------------------------------
    // KIRIM NOTIFIKASI — Firebase first, WA fallback
    // -------------------------------------------------------
    // ===================================================================
    // TAMBAHAN di IuranNotificationService.php
    // Ganti method kirimNotifikasi (private) yang lama dengan versi ini,
    // lalu tambahkan method publik kirimNotifikasiManual di bawahnya.
    // ===================================================================

    /**
     * Kirim notifikasi: Firebase dulu, fallback ke WA jika gagal/tidak ada device.
     * Dipakai baik oleh scheduler (auto) maupun controller (manual oleh admin).
     *
     * @param  bool  $checkDuplicate  Kalau true, cek NotificationLog dulu supaya
     *                                tidak kirim dobel (dipakai scheduler).
     *                                Untuk kirim manual oleh admin, set false,
     *                                karena admin memang sengaja ingin kirim ulang.
     * @return string  'firebase' | 'whatsapp' | 'gagal'
     */
    public function kirimNotifikasi(
        $warga,
        $iuran,
        string $type,
        ?string $periode,
        string $title,
        string $message,
        bool $checkDuplicate = true,
    ): string {
        if ($checkDuplicate) {
            $sudahDikirim = NotificationLog::where('nik', $warga->nik)
                ->where('id_informasi_iuran', $iuran->id)
                ->where('type', $type)
                ->where('periode', $periode)
                ->where('is_sent', true)
                ->exists();

            if ($sudahDikirim) return 'sudah_dikirim';
        }

        $berhasil = false;
        $channel  = null;

        // 1. Coba Firebase dulu
        $fcmTokens = $warga->user?->devices
            ->pluck('fcm_token')
            ->filter()
            ->values()
            ->toArray() ?? [];

        if (!empty($fcmTokens)) {
            try {
                $this->firebase->sendBulk($fcmTokens, $title, $message);
                $berhasil = true;
                $channel  = 'firebase';
            } catch (\Throwable $e) {
                Log::warning('Firebase gagal, fallback ke WA', [
                    'nik'   => $warga->nik,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Fallback ke WA jika Firebase gagal atau tidak ada token
        if (!$berhasil && $warga->no_hp) {
            $berhasil = $this->fonnte->send($warga->no_hp, $message, delay: 15);
            $channel  = 'whatsapp';
        }

        // Simpan ke riwayat notifikasi in-app (kalau warga punya akun)
        if ($warga->user) {
            \App\Models\Notification::create([
                'title'   => $title,
                'message' => $message,
                'type'    => 'pengingat',
                'user_id' => $warga->user->id,
                'data'    => ['id_informasi_iuran' => $iuran->id],
            ]);
        }

        // Simpan/update log (dipakai juga untuk dedupe scheduler nanti)
        NotificationLog::updateOrCreate(
            [
                'nik'                => $warga->nik,
                'id_informasi_iuran' => $iuran->id,
                'type'               => $type,
                'periode'            => $periode,
            ],
            [
                'is_sent' => $berhasil,
                'sent_at' => $berhasil ? now() : null,
                'message' => $message,
            ]
        );

        Log::info('Notifikasi iuran', [
            'nik'      => $warga->nik,
            'nama'     => $warga->nama_warga,
            'type'     => $type,
            'channel'  => $channel,
            'berhasil' => $berhasil,
        ]);

        return $berhasil ? $channel : 'gagal';
    }

    // ===================================================================
// TAMBAHAN di IuranNotificationService.php
// Method ini untuk kasus kirim pesan bebas (tidak terikat 1 iuran spesifik),
// misalnya ringkasan gabungan beberapa tunggakan iuran sekaligus.
// Beda dengan kirimNotifikasi() yang butuh $iuran/$type/$periode untuk
// keperluan dedupe di NotificationLog — method ini tidak menyentuh
// NotificationLog sama sekali karena tidak relevan untuk kasus ini.
// ===================================================================

    /**
     * Kirim pesan bebas ke satu warga: Firebase dulu, fallback WA jika gagal/tidak ada device.
     *
     * @return string  'firebase' | 'whatsapp' | 'gagal'
     */
    public function kirimPesanKeWarga($warga, string $title, string $message, string $type = 'pengingat'): string
    {
        $berhasil = false;
        $channel  = null;

        // 1. Coba Firebase dulu
        $fcmTokens = $warga->user?->devices
            ->pluck('fcm_token')
            ->filter()
            ->values()
            ->toArray() ?? [];

        if (!empty($fcmTokens)) {
            try {
                $this->firebase->sendBulk($fcmTokens, $title, $message);
                $berhasil = true;
                $channel  = 'firebase';
            } catch (\Throwable $e) {
                Log::warning('Firebase gagal, fallback ke WA', [
                    'nik'   => $warga->nik,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Fallback ke WA jika Firebase gagal atau tidak ada token
        if (!$berhasil && $warga->no_hp) {
            $berhasil = $this->fonnte->send($warga->no_hp, $message, delay: 15);
            $channel  = 'whatsapp';
        }

        // Simpan ke riwayat notifikasi in-app (kalau warga punya akun)
        if ($warga->user) {
            \App\Models\Notification::create([
                'title'   => $title,
                'message' => $message,
                'type'    => $type,
                'user_id' => $warga->user->id,
            ]);
        }

        Log::info('Kirim pesan ke warga', [
            'nik'      => $warga->nik,
            'nama'     => $warga->nama_warga,
            'channel'  => $channel,
            'berhasil' => $berhasil,
        ]);

        return $berhasil ? $channel : 'gagal';
    }

    // -------------------------------------------------------
    // Firebase ke semua warga (untuk notif iuran baru)
    // -------------------------------------------------------
    private function notifikasiFirebaseAllWarga(string $title, string $body): void
    {
        $tokens = \App\Models\UserDevice::whereHas('user', function ($q) {
            $q->where('role', 'warga')->whereNull('deleted_at');
        })
            ->pluck('fcm_token')
            ->filter()
            ->values()
            ->toArray();

        if (empty($tokens)) return;

        try {
            $this->firebase->sendBulk($tokens, $title, $body);
        } catch (\Throwable $e) {
            Log::error('Firebase bulk error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------
    // FORMAT PESAN
    // -------------------------------------------------------
    private function pesanBulanan(
        string $nama,
        InformasiIuran $iuran,
        int $bulan,
        string $tahun,
        string $type
    ): string {
        $namaBulan = Carbon::create()->month($bulan)->translatedFormat('F');
        $nominal   = number_format($iuran->jumlah_iuran, 0, ',', '.');

        $kalimat = match ($type) {
            'bulanan_awal'   => "Ini adalah pengingat awal bahwa iuran bulan *{$namaBulan} {$tahun}* sudah dapat dibayarkan.",
            'bulanan_tengah' => "Hingga saat ini, iuran bulan *{$namaBulan} {$tahun}* Anda belum kami terima.",
            'bulanan_akhir'  => "Ini adalah pengingat terakhir. Bulan *{$namaBulan} {$tahun}* akan segera berakhir dan iuran Anda belum kami terima.",
            default          => '',
        };

        return <<<MSG
        Yth. Bapak/Ibu *{$nama}*,

        {$kalimat}

        📋 *Detail Iuran:*
        • Jenis   : {$iuran->judul_iuran}
        • Bulan   : {$namaBulan} {$tahun}
        • Nominal : Rp {$nominal}

        Mohon segera melakukan pembayaran melalui aplikasi atau langsung kepada pengurus banjar.

        Terima kasih atas perhatian dan kerja samanya. 🙏
        _Pengurus Banjar_
        MSG;
    }

    private function pesanKematian(
        string $nama,
        InformasiIuran $iuran,
        int $selisihHari,
        string $type
    ): string {
        $nominal  = number_format($iuran->jumlah_iuran, 0, ',', '.');
        $almarhum = $iuran->nama_warga_meninggal
            ? " atas nama almarhum/almarhumah *{$iuran->nama_warga_meninggal}*"
            : '';

        $kalimat = match ($type) {
            'kematian_h1'  => "Kami ingin menginformasikan bahwa telah diadakan iuran kematian yang perlu segera diselesaikan.",
            'kematian_h7'  => "Sudah *7 hari* sejak iuran kematian ini dibuat, namun pembayaran Anda belum kami terima.",
            'kematian_h30' => "Sudah *30 hari* sejak iuran kematian ini dibuat. Mohon segera menyelesaikan kewajiban pembayaran.",
            default        => '',
        };

        return <<<MSG
        Yth. Bapak/Ibu *{$nama}*,

        {$kalimat}

        📋 *Detail Iuran:*
        • Jenis   : {$iuran->judul_iuran}{$almarhum}
        • Nominal : Rp {$nominal}

        Mohon segera melakukan pembayaran melalui aplikasi atau langsung kepada pengurus banjar.

        Terima kasih atas perhatian dan kerja samanya. 🙏
        _Pengurus Banjar_
        MSG;
    }
}
