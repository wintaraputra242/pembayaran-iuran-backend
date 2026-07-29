<?php

namespace App\Http\Controllers\Client;

use App\Models\InformasiIuran;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WargaChatbotController extends Controller
{
  private array $intents = [
    'belum_bayar'       => ['belum bayar', 'belum dibayar', 'belum saya bayar', 'apa yang belum', 'tunggakan', 'hutang iuran'],
    'status_pembayaran' => ['status pembayaran', 'sudah diterima', 'sudah divalidasi', 'cek pembayaran', 'pembayaran saya'],
    'total_tagihan'     => ['total tagihan', 'berapa yang harus', 'berapa tagihan', 'total bayar', 'berapa hutang'],
    'riwayat'           => ['riwayat', 'history', 'sudah bayar', 'pembayaran yang sudah', 'pernah bayar'],
    'iuran_aktif'       => ['iuran aktif', 'iuran berjalan', 'iuran apa', 'iuran sekarang', 'informasi iuran'],
    'iuran_kematian'    => ['iuran kematian', 'solidaritas', 'kematian'],
    'bulan_belum_bayar' => ['bulan mana', 'bulan apa', 'bulan yang belum', 'bulan belum'],
  ];

  public function chat(Request $request)
  {
    $request->validate(['message' => 'required|string|max:500']);

    $user  = $request->user();
    $warga = $user->warga;

    if (!$warga) {
      return response()->json([
        'status'  => true,
        'message' => 'Data warga tidak ditemukan.',
        'data'    => null,
      ]);
    }

    $message = strtolower(trim($request->message));
    $intent  = $this->detectIntent($message);
    $result  = $this->handleIntent($intent, $warga);

    return response()->json([
      'status'  => true,
      'intent'  => $intent,
      'message' => $result['message'],
      'data'    => $result['data'] ?? null,
    ]);
  }

  public function suggestedQuestions()
  {
    return response()->json([
      'status' => true,
      'data'   => [
        [
          'kategori'  => 'Tagihan',
          'icon'      => 'ri-bill-line',
          'questions' => [
            'Iuran apa saja yang belum saya bayar?',
            'Berapa total tagihan saya?',
            'Bulan mana saja yang belum saya bayar?',
          ],
        ],
        [
          'kategori'  => 'Pembayaran',
          'icon'      => 'ri-money-dollar-circle-line',
          'questions' => [
            'Cek status pembayaran saya',
            'Tampilkan riwayat pembayaran saya',
          ],
        ],
        [
          'kategori'  => 'Informasi Iuran',
          'icon'      => 'ri-file-list-line',
          'questions' => [
            'Iuran apa yang sedang berjalan?',
            'Ada iuran kematian yang aktif?',
          ],
        ],
      ],
    ]);
  }

  // -------------------------------------------------------
  // INTENT DETECTION
  // -------------------------------------------------------
  private function detectIntent(string $message): string
  {
    foreach ($this->intents as $intent => $keywords) {
      foreach ($keywords as $keyword) {
        if (str_contains($message, $keyword)) {
          return $intent;
        }
      }
    }
    return 'unknown';
  }

  // -------------------------------------------------------
  // HANDLE INTENT
  // -------------------------------------------------------
  private function handleIntent(string $intent, $warga): array
  {
    return match ($intent) {
      'belum_bayar'       => $this->getBelumBayar($warga),
      'status_pembayaran' => $this->getStatusPembayaran($warga),
      'total_tagihan'     => $this->getTotalTagihan($warga),
      'riwayat'           => $this->getRiwayat($warga),
      'iuran_aktif'       => $this->getIuranAktif($warga),
      'iuran_kematian'    => $this->getIuranKematian($warga),
      'bulan_belum_bayar' => $this->getBulanBelumBayar($warga),
      default             => $this->getUnknown(),
    };
  }

  private function getIuranQueryForWarga($warga)
  {
    $tahunBergabung = (int) $warga->created_at->format('Y');

    $query = InformasiIuran::where('status_aktif', true)
      ->whereNull('deleted_at')
      ->where(function ($q) use ($warga, $tahunBergabung) {
        // Iuran bulanan — hanya tampilkan periode >= tahun bergabung
        // dan <= tahun nonaktif (kalau nonaktif)
        $q->where(function ($q1) use ($warga, $tahunBergabung) {
          $q1->where('jenis_iuran', 'bulanan')
            ->where(function ($q2) use ($warga, $tahunBergabung) {
              $q2->whereNull('periode')
                ->orWhere(function ($q3) use ($warga, $tahunBergabung) {
                  $q3->where('periode', '>=', $tahunBergabung);

                  // Kalau nonaktif — periode <= tahun nonaktif
                  if (
                    $warga->status_keaktifan === 'tidak_aktif'
                    && $warga->tanggal_nonaktif
                  ) {
                    $tahunNonaktif = (int) Carbon::parse($warga->tanggal_nonaktif)->format('Y');
                    $q3->where('periode', '<=', $tahunNonaktif);
                  }
                });
            });
        })
          // Iuran kematian — hanya tampilkan yang dibuat setelah warga bergabung
          ->orWhere(function ($q1) use ($warga) {
            $q1->where('jenis_iuran', 'kematian')
              ->where('created_at', '>=', $warga->created_at);

            // Kalau nonaktif — hanya tampilkan iuran sebelum/saat nonaktif
            if (
              $warga->status_keaktifan === 'tidak_aktif'
              && $warga->tanggal_nonaktif
            ) {
              $q1->whereDate('created_at', '<=', $warga->tanggal_nonaktif);
            }
          });
      });

    return $query;
  }

  // -------------------------------------------------------
  // HANDLERS
  // -------------------------------------------------------
  private function getBelumBayar($warga): array
  {
    $iuranList  = $this->getIuranQueryForWarga($warga)->get();
    $belumBayar = [];

    foreach ($iuranList as $iuran) {
      $sudahBayar = Pembayaran::where('nik', $warga->nik)
        ->where('id_informasi_iuran', $iuran->id)
        ->whereIn('status_bayar', ['approved', 'pending'])
        ->exists();

      if (!$sudahBayar) {
        $belumBayar[] = [
          'id'           => $iuran->id,
          'judul_iuran'  => $iuran->judul_iuran,
          'jenis_iuran'  => $iuran->jenis_iuran,
          'jumlah_iuran' => 'Rp ' . number_format($iuran->jumlah_iuran, 0, ',', '.'),
        ];
      }
    }

    if (empty($belumBayar)) {
      return [
        'message' => "🎉 Semua iuran Anda sudah terbayar! Tidak ada tunggakan saat ini.",
        'data'    => [],
      ];
    }

    $jumlah = count($belumBayar);

    return [
      'message' => "📋 Terdapat *{$jumlah} iuran* yang belum Anda bayar:",
      'data'    => $belumBayar,
    ];
  }

  private function getStatusPembayaran($warga): array
  {
    $pembayaran = Pembayaran::with('informasiIuran')
      ->where('nik', $warga->nik)
      ->whereIn('status_bayar', ['pending', 'approved', 'rejected'])
      ->latest()
      ->take(5)
      ->get();

    if ($pembayaran->isEmpty()) {
      return ['message' => '📭 Belum ada riwayat pembayaran yang ditemukan.'];
    }

    $statusMap = [
      'pending'  => '⏳ Menunggu validasi',
      'approved' => '✅ Diterima',
      'rejected' => '❌ Ditolak',
    ];

    return [
      'message' => "📊 Berikut status *{$pembayaran->count()} pembayaran* terakhir Anda:",
      'data'    => $pembayaran->map(fn($p) => [
        'judul_iuran' => $p->informasiIuran->judul_iuran ?? '-',
        'status'      => $statusMap[$p->status_bayar] ?? $p->status_bayar,
        'total_bayar' => 'Rp ' . number_format($p->total_bayar, 0, ',', '.'),
        'tanggal'     => Carbon::parse($p->tanggal_bayar)->translatedFormat('d F Y'),
      ]),
    ];
  }

  private function getTotalTagihan($warga): array
  {
    $iuranList  = $this->getIuranQueryForWarga($warga)->get();
    $total      = 0;
    $jumlahItem = 0;

    foreach ($iuranList as $iuran) {
      $sudahBayar = Pembayaran::where('nik', $warga->nik)
        ->where('id_informasi_iuran', $iuran->id)
        ->whereIn('status_bayar', ['approved', 'pending'])
        ->exists();

      if (!$sudahBayar) {
        $total += $iuran->jumlah_iuran;
        $jumlahItem++;
      }
    }

    if ($jumlahItem === 0) {
      return ['message' => "✅ Tidak ada tagihan. Semua iuran Anda sudah terbayar!"];
    }

    $formatted = 'Rp ' . number_format($total, 0, ',', '.');

    return [
      'message' => "💰 Total tagihan Anda saat ini adalah *{$formatted}* dari *{$jumlahItem} iuran* yang belum dibayar.",
    ];
  }

  private function getRiwayat($warga): array
  {
    $pembayaran = Pembayaran::with('informasiIuran')
      ->where('nik', $warga->nik)
      ->where('status_bayar', 'approved')
      ->latest('tanggal_bayar')
      ->take(5)
      ->get();

    if ($pembayaran->isEmpty()) {
      return ['message' => '📭 Belum ada riwayat pembayaran yang berhasil.'];
    }

    return [
      'message' => "📜 Berikut *{$pembayaran->count()} riwayat* pembayaran terakhir Anda:",
      'data'    => $pembayaran->map(fn($p) => [
        'judul_iuran' => $p->informasiIuran->judul_iuran ?? '-',
        'total_bayar' => 'Rp ' . number_format($p->total_bayar, 0, ',', '.'),
        'tanggal'     => Carbon::parse($p->tanggal_bayar)->translatedFormat('d F Y'),
      ]),
    ];
  }

  private function getIuranAktif($warga): array
  {
    $iuranList = $this->getIuranQueryForWarga($warga)->get();

    if ($iuranList->isEmpty()) {
      return ['message' => '📭 Tidak ada iuran yang sedang aktif untuk Anda saat ini.'];
    }

    return [
      'message' => "📋 Terdapat *{$iuranList->count()} iuran* yang berlaku untuk Anda:",
      'data'    => $iuranList->map(fn($i) => [
        'id'           => $i->id,
        'judul_iuran'  => $i->judul_iuran,
        'jenis_iuran'  => $i->jenis_iuran,
        'jumlah_iuran' => 'Rp ' . number_format($i->jumlah_iuran, 0, ',', '.'),
      ]),
    ];
  }

  private function getIuranKematian($warga): array
  {
    $iuranList = $this->getIuranQueryForWarga($warga)
      ->where('jenis_iuran', 'kematian')
      ->get();

    if ($iuranList->isEmpty()) {
      return ['message' => '✅ Tidak ada iuran kematian yang aktif untuk Anda saat ini.'];
    }

    return [
      'message' => "🕊️ Terdapat *{$iuranList->count()} iuran kematian* yang berlaku untuk Anda:",
      'data'    => $iuranList->map(fn($i) => [
        'id'                   => $i->id,
        'judul_iuran'          => $i->judul_iuran,
        'nama_warga_meninggal' => $i->nama_warga_meninggal ?? '-',
        'jumlah_iuran'         => 'Rp ' . number_format($i->jumlah_iuran, 0, ',', '.'),
      ]),
    ];
  }

  private function getBulanBelumBayar($warga): array
  {
    $tahun          = now()->format('Y');
    $tahunBergabung = (int) $warga->created_at->format('Y');
    $bulanBergabung = (int) $warga->created_at->format('n');

    $iuran = $this->getIuranQueryForWarga($warga)
      ->where('jenis_iuran', 'bulanan')
      ->where('periode', $tahun)
      ->first();

    if (!$iuran) {
      return ['message' => "Belum ada iuran bulanan aktif untuk tahun {$tahun} yang berlaku untuk Anda."];
    }

    $bulanSudahBayar = Pembayaran::where('nik', $warga->nik)
      ->where('id_informasi_iuran', $iuran->id)
      ->whereIn('status_bayar', ['approved', 'pending'])
      ->get()
      ->flatMap(fn($p) => is_array($p->bulan) ? $p->bulan : [])
      ->map(fn($b) => (int) $b)
      ->unique()
      ->values()
      ->toArray();

    // Bulan mulai berdasarkan tahun bergabung
    $bulanMulai    = ($tahunBergabung === (int) $tahun) ? $bulanBergabung : 1;
    $bulanMaksimal = 12;

    // Kalau nonaktif — bulan maksimal sesuai tanggal nonaktif
    if ($warga->status_keaktifan === 'tidak_aktif' && $warga->tanggal_nonaktif) {
      $tglNonaktif   = Carbon::parse($warga->tanggal_nonaktif);
      $tahunNonaktif = (int) $tglNonaktif->format('Y');
      $bulanNonaktif = (int) $tglNonaktif->format('n');

      if ($tahunNonaktif === (int) $tahun) {
        $bulanMaksimal = $bulanNonaktif;
      } elseif ($tahunNonaktif < (int) $tahun) {
        $bulanMaksimal = 0;
      }
    }

    $namaBulan = [
      1 => 'Januari',
      2 => 'Februari',
      3 => 'Maret',
      4 => 'April',
      5 => 'Mei',
      6 => 'Juni',
      7 => 'Juli',
      8 => 'Agustus',
      9 => 'September',
      10 => 'Oktober',
      11 => 'November',
      12 => 'Desember',
    ];

    $belumBayar = [];
    for ($b = $bulanMulai; $b <= $bulanMaksimal; $b++) {
      if (!in_array($b, $bulanSudahBayar)) {
        $belumBayar[] = $namaBulan[$b];
      }
    }

    if (empty($belumBayar)) {
      return ['message' => "🎉 Semua bulan iuran *{$iuran->judul_iuran}* sudah Anda bayar!"];
    }

    $listBulan = implode(', ', $belumBayar);

    return [
      'message' => "📅 Bulan yang belum dibayar untuk *{$iuran->judul_iuran}*:\n*{$listBulan}*",
      'data'    => $belumBayar,
    ];
  }

  private function getUnknown(): array
  {
    return [
      'message' => "Maaf, saya tidak memahami pertanyaan tersebut. 🙏\n\nCoba tanyakan dengan kata kunci seperti:\n• \"iuran apa yang belum saya bayar\"\n• \"cek status pembayaran saya\"\n• \"berapa total tagihan saya\"",
    ];
  }
}
