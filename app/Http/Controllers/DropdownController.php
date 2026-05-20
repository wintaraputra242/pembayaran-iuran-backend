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

        $user = Auth::user();
        $iuran = InformasiIuran::findOrFail($request->id_informasi_iuran);

        $reguKetua = null;

        if ($user->role === 'ketua_regu') {
            $reguKetua = AnggotaRegu::where('nik', $user->warga->nik ?? null)
                ->where('is_leader', 1)
                ->whereNull('deleted_at')
                ->value('id_regu');
        }

        $filterReguId = $request->filled('id_regu') ? $request->id_regu : null;

        $warga = Warga::with([
            'anggotaRegu.regu',
            'pembayaran' => function ($q) use ($request) {
                $q->where('id_informasi_iuran', $request->id_informasi_iuran)
                    ->where('status_bayar', 'paid');
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
            ->when($filterReguId, function ($query) use ($filterReguId) {
                $query->whereHas('anggotaRegu', function ($q) use ($filterReguId) {
                    $q->where('id_regu', $filterReguId)
                        ->whereNull('deleted_at')
                        ->where('status_keaktifan', 'aktif');
                });
            })
            ->get()
            ->filter(function ($warga) use ($iuran) {
                if ($iuran->jenis_iuran === 'kematian') {
                    return $warga->pembayaran->count() === 0;
                }

                if ($iuran->jenis_iuran === 'bulanan') {
                    $bulanSudahDibayar = [];

                    foreach ($warga->pembayaran as $bayar) {
                        if (is_array($bayar->bulan)) {
                            $bulanSudahDibayar = array_merge($bulanSudahDibayar, $bayar->bulan);
                        }
                    }

                    return count(array_unique($bulanSudahDibayar)) < 12;
                }

                return true;
            })
            ->sortBy('nama_warga')
            ->map(function ($item) {
                $anggotaAktif = $item->anggotaRegu
                    ->whereNull('deleted_at')
                    ->where('status_keaktifan', 'aktif')
                    ->first();

                return [
                    'nik'        => $item->nik,
                    'nama_warga' => $item->nama_warga,
                    'regu'       => $anggotaAktif->regu->nama_regu ?? null,
                    'regu_id'    => $anggotaAktif->regu->id ?? null,
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
