<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AnggotaRegu;
use App\Models\InformasiIuran;
use App\Models\Regu;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DropdownController extends Controller
{
    public function getDropdownWarga()
    {
        $warga = Warga::select('nik', 'nama_warga')
            ->orderBy('nama_warga')
            ->get();

        return ApiResponse::success($warga, 'Data warga berhasil diambil.');
    }

    public function getDropdownWargaForAddAnggota()
    {
        $warga = Warga::select('nik', 'nama_warga')
            ->where('status_keaktifan', 'aktif')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('anggota_regu')
                    ->whereColumn('anggota_regu.nik', 'warga.nik')
                    ->whereNull('anggota_regu.deleted_at');
            })
            ->orderBy('nama_warga')
            ->get();

        return ApiResponse::success($warga, 'Data warga berhasil diambil.');
    }

    public function getDropdownInformasiIuran()
    {
        $informasiIuran = InformasiIuran::select('id', 'judul_iuran', 'jenis_iuran', 'jumlah_iuran')
            ->orderBy('judul_iuran')
            ->get();

        return ApiResponse::success($informasiIuran, 'Data informasi iuran berhasil diambil.');
    }

    public function getDropdownRegu()
    {
        $regu = Regu::select('id', 'nama_regu')
            ->orderBy('nama_regu')
            ->get();

        return ApiResponse::success($regu, 'Data regu berhasil diambil.');
    }

    public function getDropdownWargaForPembayaran(Request $request)
    {
        $request->validate([
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
        ]);

        $user  = Auth::user();
        $iuran = InformasiIuran::findOrFail($request->id_informasi_iuran);

        $filterReguId = $request->filled('id_regu') ? $request->id_regu : null;
        $reguKetua    = null;

        if ($user->role === 'ketua_regu') {
            $reguKetua = $user->regu()->whereNull('deleted_at')->value('id');

            if (!$reguKetua) {
                return ApiResponse::success([], 'Data warga berhasil diambil.');
            }
        }

        $warga = Warga::with([
            'anggotaRegu.regu',
            'pembayaran' => function ($q) use ($request, $iuran) {
                $q->where('id_informasi_iuran', $request->id_informasi_iuran)
                    ->whereIn('status_bayar', ['paid', 'manual']);

                if ($iuran->jenis_iuran === 'bulanan') {
                    $q->whereNotNull('bulan');
                }
            },
        ])
            ->select('nik', 'nama_warga')
            ->when($reguKetua, function ($query) use ($reguKetua) {
                $query->whereHas('anggotaRegu', function ($q) use ($reguKetua) {
                    $q->where('id_regu', $reguKetua)
                        ->whereNull('deleted_at')
                        ->where('status_keaktifan', 'aktif');
                });
            })
            ->when(!$reguKetua && $filterReguId, function ($query) use ($filterReguId) {
                $query->whereHas('anggotaRegu', function ($q) use ($filterReguId) {
                    $q->where('id_regu', $filterReguId)
                        ->whereNull('deleted_at')
                        ->where('status_keaktifan', 'aktif');
                });
            })
            ->get()
            ->filter(function ($warga) use ($iuran) {
                if ($iuran->jenis_iuran === 'kematian') {
                    return $warga->pembayaran->isEmpty();
                }

                if ($iuran->jenis_iuran === 'bulanan') {
                    $bulanSudahDibayar = $warga->pembayaran
                        ->flatMap(function ($bayar) {
                            $bulan = $bayar->bulan;
                            if (is_string($bulan)) {
                                $bulan = json_decode($bulan, true) ?? [];
                            }
                            return is_array($bulan) ? $bulan : [];
                        })
                        ->map(fn($b) => (int) $b)
                        ->unique()
                        ->values();

                    return $bulanSudahDibayar->count() < 12;
                }

                return true;
            })
            ->sortBy('nama_warga')
            ->map(function ($warga) {
                $anggotaAktif = $warga->anggotaRegu
                    ->whereNull('deleted_at')
                    ->where('status_keaktifan', 'aktif')
                    ->first();

                $bulanSudahDibayar = $warga->pembayaran
                    ->flatMap(function ($bayar) {
                        $bulan = $bayar->bulan;
                        if (is_string($bulan)) {
                            $bulan = json_decode($bulan, true) ?? [];
                        }
                        return is_array($bulan) ? $bulan : [];
                    })
                    ->map(fn($b) => (int) $b)
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();

                return [
                    'nik'                 => $warga->nik,
                    'nama_warga'          => $warga->nama_warga,
                    'regu'                => $anggotaAktif->regu->nama_regu ?? null,
                    'regu_id'             => $anggotaAktif->regu->id ?? null,
                    'bulan_sudah_dibayar' => $bulanSudahDibayar,
                ];
            })
            ->values();

        return ApiResponse::success($warga, 'Data warga berhasil diambil.');
    }

    public function getDropdownAnggotaRegu()
    {
        $user = Auth::user();

        if ($user->role !== 'ketua_regu') {
            return ApiResponse::error('Akses ditolak.', null, 403);
        }

        $regu = Regu::where('id_user', $user->id)->first();

        if (!$regu) {
            return ApiResponse::error('Regu tidak ditemukan.', null, 404);
        }

        $anggota = AnggotaRegu::with(['warga:nik,nama_warga'])
            ->where('id_regu', $regu->id)
            ->whereNull('deleted_at')
            ->orderByDesc('is_leader')
            ->get()
            ->map(function ($item) {
                return [
                    'id'         => $item->id,
                    'nik'        => $item->nik,
                    'nama_warga' => $item->warga->nama_warga ?? null,
                    'is_leader'  => $item->is_leader,
                ];
            });

        return ApiResponse::success($anggota, 'Data dropdown anggota regu berhasil diambil.');
    }
}
