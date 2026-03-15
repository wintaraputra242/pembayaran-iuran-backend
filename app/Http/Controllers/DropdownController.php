<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AnggotaRegu;
use App\Models\InformasiIuran;
use App\Models\Regu;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DropdownController extends Controller
{
    public function getDropdownWarga()
    {
        $warga = Warga::select('nik', 'nama_warga')
            ->orderBy('nama_warga')
            ->get();

        return ApiResponse::success(
            $warga,
            'Data warga berhasil diambil.',
            200
        );
    }
    
    public function getDropdownWargaForAddAnggota()
    {
        $warga = Warga::select('nik', 'nama_warga')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('anggota_regu')
                    ->whereColumn('anggota_regu.nik', 'warga.nik')
                    ->whereNull('anggota_regu.deleted_at');
            })
            ->orderBy('nama_warga')
            ->get();

        return ApiResponse::success(
            $warga,
            'Data warga berhasil diambil.',
            200
        );
    }

    public function getDropdownInformasiIuran()
    {
        $informasiIuran = InformasiIuran::select('id', 'judul_iuran', 'jenis_iuran', 'jumlah_iuran')
            ->orderBy('judul_iuran')
            ->get();

        return ApiResponse::success(
            $informasiIuran,
            'Data informasi iuran berhasil diambil.',
            200
        );
    }

    public function getDropdownRegu()
    {
        $regu = Regu::select('id', 'nama_regu')
            ->orderBy('nama_regu')
            ->get();

        return ApiResponse::success(
            $regu,
            'Data regu berhasil diambil.',
            200
        );
    }

    public function getDropdownWargaForPembayaran(Request $request)
    {
        $request->validate([
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id'
        ]);

        $user = auth()->user();

        $iuran = InformasiIuran::findOrFail($request->id_informasi_iuran);

        // 🔥 Ambil regu yang dipimpin ketua regu
        $reguKetua = null;

        if ($user->role === 'ketua_regu') {
            $reguKetua = AnggotaRegu::where('nik', $user->nik)
                ->where('is_leader', 1)
                ->whereNull('deleted_at')
                ->value('id_regu');
        }

        $warga = Warga::with([
                'anggotaRegu.regu',
                'pembayaran' => function ($q) use ($request) {
                    $q->where('id_informasi_iuran', $request->id_informasi_iuran)
                    ->where('status_bayar', 'paid');
                }
            ])
            ->select('nik', 'nama_warga')

            // 🔥 Jika login sebagai ketua regu → hanya warga di regu yang dia pimpin
            ->when($reguKetua, function ($query) use ($reguKetua) {
                $query->whereHas('anggotaRegu', function ($q) use ($reguKetua) {
                    $q->where('id_regu', $reguKetua)
                    ->whereNull('deleted_at')
                    ->where('status_keaktifan', 1);
                });
            })

            // 🔥 Jika admin melakukan filter   regu
            ->when($user->regu->id, function ($query) use ($user) {
                $query->whereHas('anggotaRegu', function ($q) use ($user) {
                    $q->where('id_regu', $user->regu->id)
                    ->whereNull('deleted_at')
                    ->where('status_keaktifan', 1);
                });
            })

            ->get()

            ->filter(function ($warga) use ($iuran) {

                // 🔥 IURAN KEMATIAN
                if ($iuran->jenis_iuran === 'kematian') {
                    return $warga->pembayaran->count() === 0;
                }

                // 🔥 IURAN BULANAN
                if ($iuran->jenis_iuran === 'bulanan') {

                    $bulanSudahDibayar = [];

                    foreach ($warga->pembayaran as $bayar) {
                        if (is_array($bayar->bulan)) {
                            $bulanSudahDibayar = array_merge($bulanSudahDibayar, $bayar->bulan);
                        }
                    }

                    $bulanUnik = array_unique($bulanSudahDibayar);

                    return count($bulanUnik) < 12;
                }

                return true;
            })

            ->sortBy('nama_warga')

            ->map(function ($item) {
                return [
                    'nik' => $item->nik,
                    'nama_warga' => $item->nama_warga,
                    'regu' => $item->anggotaRegu->regu->nama_regu ?? null,
                    'regu_id' => $item->anggotaRegu->regu->id ?? null,
                ];
            })

            ->values();

        return ApiResponse::success(
            $warga,
            'Data warga berhasil diambil.',
            200
        );
    }
}
