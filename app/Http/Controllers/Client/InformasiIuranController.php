<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\InformasiIuran;
use App\Models\Pembayaran;
use Illuminate\Http\JsonResponse;

class InformasiIuranController extends Controller
{

    public function show(Request $request, $id)
    {
        $user  = $request->user();
        $warga = $user->warga;

        $iuran = InformasiIuran::with('penanggungJawab:nik,nama_warga')->find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $semuaPembayaran = Pembayaran::where('nik', $warga->nik)
            ->where('id_informasi_iuran', $iuran->id)
            ->get();

        if ($iuran->jenis_iuran === 'bulanan') {
            $iuran = $this->resolveBulanan($iuran, $semuaPembayaran, $warga); // ← pass $warga
        } else {
            $iuran = $this->resolveNonBulanan($iuran, $semuaPembayaran);
        }

        return ApiResponse::success($iuran, 'Detail informasi iuran berhasil diambil.');
    }

    public function getIuranWithStatus(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $perPage        = $request->get('per_page', 10);
        $bulanBergabung = (int) $warga->created_at->format('n');
        $tahunBergabung = (int) $warga->created_at->format('Y');

        $query = InformasiIuran::with(['penanggungJawab:nik,nama_warga'])
            ->where('status_aktif', 1)
            ->withExists([
                'pembayaran as sudah_bayar' => function ($q) use ($warga) {
                    $q->where('nik', $warga->nik)
                        ->whereIn('status_bayar', ['approved', 'pending']);
                }
            ]);

        // Filter iuran bulanan — hanya tampilkan periode >= tahun bergabung
        $query->where(function ($q) use ($warga, $tahunBergabung) {
            $q->where('jenis_iuran', 'kematian')
                ->orWhere(function ($q2) use ($warga, $tahunBergabung) {
                    $q2->where('jenis_iuran', 'bulanan')
                        ->where(function ($q3) use ($warga, $tahunBergabung) {
                            $q3->whereNull('periode')
                                ->orWhere(function ($q4) use ($warga, $tahunBergabung) {
                                    // Periode >= tahun bergabung
                                    $q4->where('periode', '>=', $tahunBergabung);

                                    // Kalau nonaktif — periode <= tahun nonaktif
                                    if (
                                        $warga->status_keaktifan === 'tidak_aktif'
                                        && $warga->tanggal_nonaktif
                                    ) {
                                        $tahunNonaktif = (int) \Carbon\Carbon::parse($warga->tanggal_nonaktif)->format('Y');
                                        $q4->where('periode', '<=', $tahunNonaktif);
                                    }
                                });
                        });
                });
        });

        // Filter iuran kematian — hanya tampilkan yang dibuat setelah warga bergabung
        $query->where(function ($q) use ($warga) {
            $q->where('jenis_iuran', 'bulanan')
                ->orWhere(function ($q2) use ($warga) {
                    $q2->where('jenis_iuran', 'kematian')
                        // Iuran dibuat setelah warga bergabung
                        ->where('created_at', '>=', $warga->created_at)
                        // Iuran dibuat sebelum atau saat warga nonaktif
                        ->where(function ($q3) use ($warga) {
                            if (
                                $warga->status_keaktifan === 'tidak_aktif'
                                && $warga->tanggal_nonaktif
                            ) {
                                // Tampilkan iuran kematian yang dibuat
                                // sebelum/saat tanggal nonaktif (inklusif)
                                $q3->whereDate(
                                    'created_at',
                                    '<=',
                                    $warga->tanggal_nonaktif
                                );
                            }
                            // Kalau warga masih aktif — tampilkan semua
                        });
                });
        });

        if ($request->filled('jenis_iuran')) {
            $query->where('jenis_iuran', $request->jenis_iuran);
        }

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('judul_iuran', 'like', "%{$keyword}%")
                    ->orWhere('nama_warga_meninggal', 'like', "%{$keyword}%")
                    ->orWhere('periode', 'like', "%{$keyword}%")
                    ->orWhereHas('penanggungJawab', function ($sub) use ($keyword) {
                        $sub->where('nama_warga', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($request->filled('status_bayar')) {
            if ($request->status_bayar === 'sudah_bayar') {
                $query->whereHas('pembayaran', function ($q) use ($warga) {
                    $q->where('nik', $warga->nik)
                        ->whereIn('status_bayar', ['approved', 'pending']);
                });
            } elseif ($request->status_bayar === 'belum_bayar') {
                $query->whereDoesntHave('pembayaran', function ($q) use ($warga) {
                    $q->where('nik', $warga->nik)
                        ->whereIn('status_bayar', ['approved', 'pending']);
                });
            }
        }

        $query->orderByRaw('sudah_bayar ASC')->orderBy('judul_iuran');

        $data = $query->paginate($perPage);

        $data->getCollection()->transform(function ($iuran) use ($warga, $bulanBergabung, $tahunBergabung) {
            $semuaPembayaran = Pembayaran::where('nik', $warga->nik)
                ->where('id_informasi_iuran', $iuran->id)
                ->get();

            if ($iuran->jenis_iuran === 'bulanan') {
                $iuran = $this->resolveBulanan($iuran, $semuaPembayaran, $warga); // ← pass $warga
            } else {
                $iuran = $this->resolveNonBulanan($iuran, $semuaPembayaran);
            }

            unset($iuran->sudah_bayar);
            return $iuran;
        });

        return ApiResponse::success($data, 'Data informasi iuran berhasil diambil.');
    }

    /**
     * Prioritas status: approved > pending > rejected
     */
    private function getBestStatus(array $statuses): ?string
    {
        $priority = ['approved' => 3, 'pending' => 2, 'rejected' => 1];

        return collect($statuses)
            ->sortByDesc(fn($s) => $priority[$s] ?? 0)
            ->first();
    }

    private function resolveNonBulanan($iuran, $semuaPembayaran)
    {
        // Ambil status terbaik dari semua record pembayaran iuran ini
        $bestStatus = $this->getBestStatus(
            $semuaPembayaran->pluck('status_bayar')->toArray()
        );

        // Record dengan status terbaik
        $priority   = ['approved' => 3, 'pending' => 2, 'rejected' => 1];
        $bestRecord = $semuaPembayaran
            ->sortByDesc(fn($p) => $priority[$p->status_bayar] ?? 0)
            ->first();

        $iuran->status_bayar  = $bestStatus ?? 'belum_bayar';
        $iuran->tanggal_bayar = $bestRecord?->tanggal_bayar;
        $iuran->id_pembayaran = $bestRecord?->id;

        return $iuran;
    }

    private function resolveBulanan($iuran, $semuaPembayaran, $warga = null)
    {
        $priority = ['approved' => 3, 'pending' => 2, 'rejected' => 1];

        $statusPerBulan = $semuaPembayaran
            ->groupBy('bulan')
            ->map(function ($records) use ($priority) {
                $best = $records->sortByDesc(fn($p) => $priority[$p->status_bayar] ?? 0)->first();
                return [
                    'status'        => $best->status_bayar,
                    'id_pembayaran' => $best->id,
                    'tanggal_bayar' => $best->tanggal_bayar,
                ];
            });

        $bulanApproved = $statusPerBulan->filter(fn($b) => $b['status'] === 'approved')->keys()->sort()->values();
        $bulanPending  = $statusPerBulan->filter(fn($b) => $b['status'] === 'pending')->keys()->sort()->values();
        $bulanRejected = $statusPerBulan->filter(fn($b) => $b['status'] === 'rejected')->keys()->sort()->values();

        $totalBulanTerhitung = $bulanApproved->count() + $bulanPending->count();

        // -------------------------------------------------------
        // Hitung total bulan WAJIB bayar berdasarkan warga
        // -------------------------------------------------------
        $tahunPeriode  = (int) $iuran->periode;
        $bulanMulai    = 1;
        $bulanMaksimal = 12;

        if ($warga) {
            $tahunBergabung = (int) $warga->created_at->format('Y');
            $bulanBergabung = (int) $warga->created_at->format('n');

            if ($tahunBergabung === $tahunPeriode) {
                $bulanMulai = $bulanBergabung;
            }

            if ($warga->status_keaktifan === 'tidak_aktif' && $warga->tanggal_nonaktif) {
                $tglNonaktif   = \Carbon\Carbon::parse($warga->tanggal_nonaktif);
                $tahunNonaktif = (int) $tglNonaktif->format('Y');
                $bulanNonaktif = (int) $tglNonaktif->format('n');

                if ($tahunNonaktif === $tahunPeriode) {
                    $bulanMaksimal = $bulanNonaktif;
                } elseif ($tahunNonaktif < $tahunPeriode) {
                    $bulanMaksimal = 0;
                }
            }
        }

        // Total bulan yang wajib dibayar untuk warga ini
        $totalBulanWajib = max(0, $bulanMaksimal - $bulanMulai + 1);

        // -------------------------------------------------------
        // Tentukan status berdasarkan total bulan WAJIB
        // -------------------------------------------------------
        if ($totalBulanTerhitung === 0) {
            $statusUtama = 'belum_bayar';
        } elseif ($bulanApproved->count() >= $totalBulanWajib && $totalBulanWajib > 0) {
            // Semua bulan wajib sudah approved → lunas
            $statusUtama = 'sudah_bayar';
        } else {
            $statusUtama = 'sebagian_bayar';
        }

        $lastRecord = $semuaPembayaran
            ->whereIn('status_bayar', ['approved', 'pending'])
            ->sortByDesc('tanggal_bayar')
            ->first();

        $iuran->status_bayar              = $statusUtama;
        $iuran->bulan_approved            = $bulanApproved;
        $iuran->bulan_pending             = $bulanPending;
        $iuran->bulan_rejected            = $bulanRejected;
        $iuran->total_bulan_approved      = $bulanApproved->count();
        $iuran->total_bulan_pending       = $bulanPending->count();
        $iuran->total_bulan_terhitung     = $totalBulanTerhitung;
        $iuran->total_bulan_wajib         = $totalBulanWajib;   // ← info tambahan
        $iuran->tanggal_bayar             = $lastRecord?->tanggal_bayar;
        $iuran->id_pembayaran             = $lastRecord?->id;
        $iuran->bulan_mulai_bayar         = $bulanMulai;
        $iuran->bulan_maksimal_bayar      = $bulanMaksimal;

        return $iuran;
    }
}
