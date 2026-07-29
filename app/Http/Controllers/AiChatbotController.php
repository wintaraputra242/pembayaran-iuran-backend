<?php

namespace App\Http\Controllers;

use App\Helpers\WargaHelper;
use App\Models\InformasiIuran;
use App\Models\Pembayaran;
use App\Models\Warga;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiChatbotController extends Controller
{
    private array $intents = [
        'belum_bayar_bulan_ini'  => ['belum bayar bulan ini', 'belum bayar bulan', 'belum bayar ini', 'siapa belum bayar'],
        'total_pemasukan_bulan'  => ['total pemasukan bulan ini', 'pemasukan bulan ini', 'total pemasukan', 'berapa pemasukan'],
        'total_tunggakan'        => ['total tunggakan', 'berapa tunggakan', 'tunggakan warga', 'total hutang'],
        'pembayaran_hari_ini'    => ['pembayaran hari ini', 'bayar hari ini', 'transaksi hari ini'],
        'jumlah_warga'           => ['jumlah warga', 'berapa warga', 'total warga', 'jumlah kk', 'berapa kk'],
        'warga_aktif'            => ['warga aktif', 'berapa warga aktif', 'jumlah warga aktif'],
        'persentase_bayar'       => ['persentase', 'persen warga', 'berapa persen', 'tingkat kepatuhan'],
        'bulan_tertinggi'        => ['bulan tertinggi', 'bulan paling tinggi', 'pembayaran tertinggi'],
        'rata_rata_pembayaran'   => ['rata-rata pembayaran', 'rata rata pembayaran', 'rata pembayaran'],
        'siapa_perlu_notifikasi' => ['siapa notifikasi', 'perlu notifikasi', 'kirim notifikasi', 'siapa reminder'],
        'warga_sering_terlambat' => ['sering terlambat', 'paling sering terlambat', 'warga terlambat', 'siapa terlambat'],
        'ringkasan_bulan_ini'    => ['ringkasan bulan ini', 'ringkas bulan ini', 'ringkasan pembayaran', 'kondisi pembayaran'],
    ];

    public function chat(Request $request)
    {
        $request->validate(['message' => 'required|string|max:500']);

        $user    = Auth::user();
        $reguId  = $this->getReguId($user);
        $message = strtolower(trim($request->message));
        $intent  = $this->detectIntent($message);
        $result  = $this->handleIntent($intent, $message, $reguId);

        return response()->json([
            'status'  => true,
            'intent'  => $intent,
            'message' => $result['message'],
            'data'    => $result['data'] ?? null,
        ]);
    }

    public function suggestedQuestions()
    {
        $user   = Auth::user();
        $isKetua = $user->role === 'ketua_regu';

        $pembayaranQuestions = $isKetua
            ? [
                'Siapa saja anggota regu yang belum bayar bulan ini?',
                'Berapa total pemasukan dari regu saya bulan ini?',
                'Berapa tunggakan anggota regu saya?',
            ]
            : [
                'Siapa saja yang belum bayar bulan ini?',
                'Berapa total pemasukan bulan ini?',
                'Berapa total tunggakan seluruh warga?',
                'Tampilkan pembayaran hari ini',
            ];

        $wargaQuestions = $isKetua
            ? [
                'Berapa jumlah anggota regu saya?',
                'Berapa anggota regu saya yang aktif?',
            ]
            : [
                'Berapa jumlah warga aktif?',
                'Berapa jumlah KK?',
            ];

        $data = [
            [
                'kategori'  => 'Pembayaran',
                'icon'      => 'ri-money-dollar-circle-line',
                'questions' => $pembayaranQuestions,
            ],
            [
                'kategori'  => $isKetua ? 'Anggota Regu' : 'Warga',
                'icon'      => 'ri-group-line',
                'questions' => $wargaQuestions,
            ],
            [
                'kategori'  => 'Ringkasan',
                'icon'      => 'ri-file-text-line',
                'questions' => [
                    'Ringkas pembayaran bulan ini',
                    'Siapa yang paling sering terlambat?',
                    'Siapa saja yang perlu dikirim notifikasi?',
                ],
            ],
        ];

        // Tambah statistik hanya untuk admin
        if (!$isKetua) {
            array_splice($data, 2, 0, [[
                'kategori'  => 'Statistik',
                'icon'      => 'ri-bar-chart-line',
                'questions' => [
                    'Berapa persentase warga yang sudah membayar?',
                    'Bulan apa pembayaran paling tinggi?',
                    'Berapa rata-rata pembayaran per bulan?',
                ],
            ]]);
        }

        return response()->json(['status' => true, 'data' => $data]);
    }

    // -------------------------------------------------------
    // HELPER — ambil regu_id kalau ketua_regu
    // -------------------------------------------------------
    private function getReguId($user): ?int
    {
        if ($user->role !== 'ketua_regu') return null;

        return $user->regu()->whereNull('deleted_at')->value('id');
    }

    // -------------------------------------------------------
    // HELPER — base query warga dengan filter regu
    // -------------------------------------------------------
    private function wargaQuery(?int $reguId)
    {
        $query = Warga::whereNull('deleted_at');

        if ($reguId) {
            $query->whereHas('anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)
                    ->whereNull('deleted_at')
                    ->where('status_keaktifan', 'aktif');
            });
        }

        return $query;
    }

    // -------------------------------------------------------
    // INTENT DETECTION
    // -------------------------------------------------------
    private function detectIntent(string $message): string
    {
        foreach ($this->intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($message, $keyword)) return $intent;
            }
        }
        return 'unknown';
    }

    // -------------------------------------------------------
    // HANDLE INTENT
    // -------------------------------------------------------
    private function handleIntent(string $intent, string $message, ?int $reguId): array
    {
        return match ($intent) {
            'belum_bayar_bulan_ini'  => $this->getBelumBayarBulanIni($reguId),
            'total_pemasukan_bulan'  => $this->getTotalPemasukanBulan($reguId),
            'total_tunggakan'        => $this->getTotalTunggakan($reguId),
            'pembayaran_hari_ini'    => $this->getPembayaranHariIni($reguId),
            'jumlah_warga'           => $this->getJumlahWarga($reguId),
            'warga_aktif'            => $this->getWargaAktif($reguId),
            'persentase_bayar'       => $this->getPersentaseBayar($reguId),
            'bulan_tertinggi'        => $this->getBulanTertinggi($reguId),
            'rata_rata_pembayaran'   => $this->getRataRataPembayaran($reguId),
            'siapa_perlu_notifikasi' => $this->getSiapaPerluNotifikasi($reguId),
            'warga_sering_terlambat' => $this->getWargaSeringTerlambat($reguId),
            'ringkasan_bulan_ini'    => $this->getRingkasanBulanIni($reguId),
            default                  => $this->getUnknown(),
        };
    }

    // -------------------------------------------------------
    // HANDLERS — semua terima $reguId
    // -------------------------------------------------------
    private function getBelumBayarBulanIni(?int $reguId): array
    {
        $bulan = (int) now()->format('n');
        $tahun = now()->format('Y');

        $iuran = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->first();

        if (!$iuran) {
            return ['message' => "Belum ada iuran bulanan yang aktif untuk tahun {$tahun}."];
        }

        $sudahBayarNik = Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->where('status_bayar', 'approved')
            ->whereJsonContains('bulan', (string) $bulan)
            ->pluck('nik')
            ->toArray();

        $belumBayar = WargaHelper::getWargaWajibBayar($bulan, $tahun, $sudahBayarNik, $reguId);

        $namaBulan = Carbon::create()->month($bulan)->translatedFormat('F');
        $scope     = $reguId ? 'di regu Anda' : '';

        if ($belumBayar->isEmpty()) {
            return [
                'message' => "🎉 Semua warga {$scope} sudah membayar iuran bulan *{$namaBulan} {$tahun}*!",
                'data'    => [],
            ];
        }

        return [
            'message' => "Terdapat *{$belumBayar->count()} warga* {$scope} yang belum membayar iuran bulan *{$namaBulan} {$tahun}*:",
            'data'    => $belumBayar->map(fn($w) => [
                'nik'        => $w->nik,
                'nama_warga' => $w->nama_warga,
            ]),
        ];
    }

    private function getTotalPemasukanBulan(?int $reguId): array
    {
        $bulan = now()->format('n');
        $tahun = now()->format('Y');

        $query = Pembayaran::where('status_bayar', 'approved')
            ->whereMonth('tanggal_bayar', $bulan)
            ->whereYear('tanggal_bayar', $tahun);

        if ($reguId) {
            $query->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $total     = $query->sum('total_bayar');
        $namaBulan = Carbon::create()->month((int) $bulan)->translatedFormat('F');
        $formatted = 'Rp ' . number_format($total, 0, ',', '.');
        $scope     = $reguId ? ' dari regu Anda' : '';

        return [
            'message' => "💰 Total pemasukan{$scope} bulan *{$namaBulan} {$tahun}* adalah *{$formatted}*.",
        ];
    }

    private function getTotalTunggakan(?int $reguId): array
    {
        $bulan = (int) now()->format('n');
        $tahun = now()->format('Y');

        $iuran = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->first();

        if (!$iuran) {
            return ['message' => "Belum ada iuran bulanan aktif untuk tahun {$tahun}."];
        }

        $totalWarga = WargaHelper::getWargaWajibBayar($bulan, $tahun, [], $reguId)->count();

        $sudahBayarNik = Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->where('status_bayar', 'approved')
            ->whereJsonContains('bulan', (string) $bulan)
            ->pluck('nik')
            ->toArray();

        $belumBayar     = WargaHelper::getWargaWajibBayar($bulan, $tahun, $sudahBayarNik, $reguId)->count();
        $totalTunggakan = $belumBayar * $iuran->jumlah_iuran;
        $formatted      = 'Rp ' . number_format($totalTunggakan, 0, ',', '.');
        $scope          = $reguId ? ' regu Anda' : '';

        return [
            'message' => "📊 Total tunggakan{$scope} tahun {$tahun}: *{$formatted}* dari *{$belumBayar} warga* yang belum membayar.",
        ];
    }

    private function getPembayaranHariIni(?int $reguId): array
    {
        $today = now()->toDateString();

        $query = Pembayaran::with('warga')
            ->where('status_bayar', 'approved')
            ->whereDate('tanggal_bayar', $today);

        if ($reguId) {
            $query->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $pembayaran = $query->get();

        if ($pembayaran->isEmpty()) {
            return ['message' => '📭 Belum ada pembayaran yang tercatat hari ini.'];
        }

        $total = $pembayaran->sum('total_bayar');
        $scope = $reguId ? ' dari regu Anda' : '';

        return [
            'message' => "✅ Terdapat *{$pembayaran->count()} pembayaran*{$scope} hari ini dengan total *Rp " . number_format($total, 0, ',', '.') . "*.",
            'data'    => $pembayaran->map(fn($p) => [
                'nama_warga'  => $p->warga->nama_warga ?? $p->nama_warga_snapshot,
                'total_bayar' => 'Rp ' . number_format($p->total_bayar, 0, ',', '.'),
            ]),
        ];
    }

    private function getJumlahWarga(?int $reguId): array
    {
        if ($reguId) {
            $total = $this->wargaQuery($reguId)->count();
            return [
                'message' => "👥 Jumlah anggota regu Anda: *{$total} KK*.",
            ];
        }

        $total    = Warga::whereNull('deleted_at')->count();
        $aktif    = Warga::where('status_keaktifan', 'aktif')->whereNull('deleted_at')->count();
        $nonaktif = $total - $aktif;

        return [
            'message' => "👥 Total warga terdaftar: *{$total} KK*\n• Aktif: *{$aktif} KK*\n• Tidak aktif: *{$nonaktif} KK*",
        ];
    }

    private function getWargaAktif(?int $reguId): array
    {
        $query = $this->wargaQuery($reguId)->where('status_keaktifan', 'aktif');
        $aktif = $query->count();
        $scope = $reguId ? ' di regu Anda' : '';

        return [
            'message' => "✅ Jumlah warga aktif{$scope} saat ini: *{$aktif} KK*.",
        ];
    }

    private function getPersentaseBayar(?int $reguId): array
    {
        $bulan = (int) now()->format('n');
        $tahun = now()->format('Y');

        $iuran = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->first();

        if (!$iuran) {
            return ['message' => "Belum ada iuran bulanan aktif untuk tahun {$tahun}."];
        }

        $totalWarga = WargaHelper::getWargaWajibBayar($bulan, $tahun, [], $reguId)->count();

        $sudahBayarQuery = Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->where('status_bayar', 'approved')
            ->whereJsonContains('bulan', (string) $bulan);

        if ($reguId) {
            $sudahBayarQuery->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $sudahBayar = $sudahBayarQuery->distinct('nik')->count('nik');
        $persentase = $totalWarga > 0 ? round(($sudahBayar / $totalWarga) * 100, 1) : 0;
        $namaBulan  = Carbon::create()->month($bulan)->translatedFormat('F');
        $scope      = $reguId ? ' regu Anda' : '';

        return [
            'message' => "📈 Tingkat kepatuhan pembayaran{$scope} bulan *{$namaBulan} {$tahun}*:\n• Sudah bayar: *{$sudahBayar} warga* ({$persentase}%)\n• Belum bayar: *" . ($totalWarga - $sudahBayar) . " warga*",
        ];
    }

    private function getBulanTertinggi(?int $reguId): array
    {
        $tahun = now()->format('Y');

        $query = Pembayaran::where('status_bayar', 'approved')
            ->whereYear('tanggal_bayar', $tahun)
            ->whereNull('deleted_at');

        if ($reguId) {
            $query->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $data = $query->selectRaw('MONTH(tanggal_bayar) as bulan, SUM(total_bayar) as total')
            ->groupByRaw('MONTH(tanggal_bayar)')
            ->orderByDesc('total')
            ->first();

        if (!$data) {
            return ['message' => "Belum ada data pembayaran untuk tahun {$tahun}."];
        }

        $namaBulan = Carbon::create()->month($data->bulan)->translatedFormat('F');
        $formatted = 'Rp ' . number_format($data->total, 0, ',', '.');
        $scope     = $reguId ? ' regu Anda' : '';

        return [
            'message' => "🏆 Bulan dengan pembayaran tertinggi{$scope} di tahun {$tahun} adalah *{$namaBulan}* dengan total *{$formatted}*.",
        ];
    }

    private function getRataRataPembayaran(?int $reguId): array
    {
        $tahun = now()->format('Y');

        $query = Pembayaran::where('status_bayar', 'approved')
            ->whereYear('tanggal_bayar', $tahun)
            ->whereNull('deleted_at');

        if ($reguId) {
            $query->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $data = $query->selectRaw('MONTH(tanggal_bayar) as bulan, SUM(total_bayar) as total')
            ->groupByRaw('MONTH(tanggal_bayar)')
            ->get();

        if ($data->isEmpty()) {
            return ['message' => "Belum ada data pembayaran untuk tahun {$tahun}."];
        }

        $rataRata  = $data->avg('total');
        $formatted = 'Rp ' . number_format($rataRata, 0, ',', '.');
        $scope     = $reguId ? ' regu Anda' : '';

        return [
            'message' => "📊 Rata-rata pembayaran per bulan{$scope} di tahun {$tahun}: *{$formatted}*.",
        ];
    }

    private function getSiapaPerluNotifikasi(?int $reguId): array
    {
        $bulan = (int) now()->format('n');
        $tahun = now()->format('Y');

        $iuran = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->first();

        if (!$iuran) {
            return ['message' => "Belum ada iuran bulanan aktif untuk tahun {$tahun}."];
        }

        $sudahBayarNik = Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->where('status_bayar', 'approved')
            ->whereJsonContains('bulan', (string) $bulan)
            ->pluck('nik')
            ->toArray();

        $warga     = WargaHelper::getWargaWajibBayar($bulan, $tahun, $sudahBayarNik, $reguId)
            ->filter(fn($w) => $w->no_hp);
        $namaBulan = Carbon::create()->month($bulan)->translatedFormat('F');
        $scope     = $reguId ? ' di regu Anda' : '';

        if ($warga->isEmpty()) {
            return ['message' => "✅ Tidak ada warga{$scope} yang perlu dikirim notifikasi untuk bulan *{$namaBulan}*."];
        }

        return [
            'message' => "🔔 Terdapat *{$warga->count()} warga*{$scope} yang perlu dikirim notifikasi bulan *{$namaBulan} {$tahun}*:",
            'data'    => $warga->map(fn($w) => [
                'nama_warga' => $w->nama_warga,
                'no_hp'      => $w->no_hp,
            ]),
        ];
    }

    private function getWargaSeringTerlambat(?int $reguId): array
    {
        $query = Pembayaran::where('status_bayar', 'approved')
            ->whereNotNull('tanggal_bayar')
            ->with('warga');

        if ($reguId) {
            $query->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $data  = $query->get()
            ->groupBy('nik')
            ->map(function ($pembayaran, $nik) {
                return [
                    'nik'          => $nik,
                    'nama_warga'   => $pembayaran->first()->warga->nama_warga ?? $pembayaran->first()->nama_warga_snapshot,
                    'jumlah_bayar' => $pembayaran->count(),
                ];
            })
            ->sortBy('jumlah_bayar')
            ->take(5)
            ->values();

        $scope = $reguId ? ' di regu Anda' : '';

        if ($data->isEmpty()) {
            return ['message' => 'Belum ada data pembayaran yang cukup untuk analisis.'];
        }

        return [
            'message' => "⚠️ Berikut *5 warga*{$scope} dengan riwayat pembayaran paling sedikit:",
            'data'    => $data,
        ];
    }

    private function getRingkasanBulanIni(?int $reguId): array
    {
        $bulan     = (int) now()->format('n');
        $tahun     = now()->format('Y');
        $namaBulan = Carbon::create()->month($bulan)->translatedFormat('F');

        $iuran = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->first();

        $totalWarga = WargaHelper::getWargaWajibBayar($bulan, $tahun, [], $reguId)->count();

        $sudahBayarQuery = $iuran
            ? Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->where('status_bayar', 'approved')
            ->whereJsonContains('bulan', (string) $bulan)
            : null;

        if ($reguId && $sudahBayarQuery) {
            $sudahBayarQuery->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $sudahBayar = $sudahBayarQuery ? $sudahBayarQuery->distinct('nik')->count('nik') : 0;
        $belumBayar = $totalWarga - $sudahBayar;
        $persentase = $totalWarga > 0 ? round(($sudahBayar / $totalWarga) * 100, 1) : 0;

        $pemasukanQuery = Pembayaran::where('status_bayar', 'approved')
            ->whereMonth('tanggal_bayar', $bulan)
            ->whereYear('tanggal_bayar', $tahun);

        if ($reguId) {
            $pemasukanQuery->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                $q->where('id_regu', $reguId)->whereNull('deleted_at');
            });
        }

        $totalPemasukan = $pemasukanQuery->sum('total_bayar');
        $formatted      = 'Rp ' . number_format($totalPemasukan, 0, ',', '.');
        $scope          = $reguId ? ' regu Anda' : '';

        return [
            'message' => "📋 *Ringkasan Pembayaran{$scope} {$namaBulan} {$tahun}*\n\n"
                . "• Total warga aktif : *{$totalWarga} KK*\n"
                . "• Sudah membayar    : *{$sudahBayar} KK* ({$persentase}%)\n"
                . "• Belum membayar    : *{$belumBayar} KK*\n"
                . "• Total pemasukan   : *{$formatted}*\n\n"
                . ($persentase >= 80
                    ? "✅ Tingkat kepatuhan bulan ini *baik*."
                    : "⚠️ Tingkat kepatuhan bulan ini masih *perlu ditingkatkan*."),
        ];
    }

    private function getUnknown(): array
    {
        return [
            'message' => "Maaf, saya tidak memahami pertanyaan tersebut. 🙏\n\nSilakan pilih dari pertanyaan yang tersedia, atau coba tanyakan dengan kata kunci seperti:\n• \"belum bayar bulan ini\"\n• \"total pemasukan\"\n• \"ringkasan bulan ini\"",
        ];
    }
}
