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
    public function getIuranWithStatus(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $perPage = $request->get('per_page', 10);

        $query = InformasiIuran::with(['penanggungJawab:nik,nama_warga'])
            ->where('status_aktif', 1)
            ->withExists([
                'pembayaran as sudah_bayar' => function ($q) use ($warga) {
                    $q->where('nik', $warga->nik)
                        ->whereIn('status_bayar', ['paid', 'manual']);
                }
            ]);

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
                        ->whereIn('status_bayar', ['paid', 'manual']);
                });
            } elseif ($request->status_bayar === 'belum_bayar') {
                $query->whereDoesntHave('pembayaran', function ($q) use ($warga) {
                    $q->where('nik', $warga->nik)
                        ->whereIn('status_bayar', ['paid', 'manual']);
                });
            }
        }

        $query->orderByRaw('sudah_bayar ASC')->orderBy('judul_iuran');

        $data = $query->paginate($perPage);

        $data->getCollection()->transform(function ($iuran) use ($warga) {
            $pembayaran = Pembayaran::where('nik', $warga->nik)
                ->where('id_informasi_iuran', $iuran->id)
                ->whereIn('status_bayar', ['paid', 'manual'])
                ->get();

            if ($iuran->jenis_iuran === 'bulanan') {
                $bulanSudahBayar = $pembayaran
                    ->pluck('bulan')
                    ->flatten()
                    ->unique()
                    ->values();

                $totalBulanBayar = $bulanSudahBayar->count();

                if ($totalBulanBayar === 0) {
                    $iuran->status_bayar = 'belum_bayar';
                } elseif ($totalBulanBayar >= 12) {
                    $iuran->status_bayar = 'sudah_bayar';
                } else {
                    $iuran->status_bayar = 'sebagian_bayar';
                }

                $iuran->bulan_sudah_bayar  = $bulanSudahBayar->sort()->values();
                $iuran->total_bulan_bayar  = $totalBulanBayar;
                $iuran->tanggal_bayar      = $pembayaran->last()?->tanggal_bayar;
                $iuran->id_pembayaran      = $pembayaran->last()?->id;
            } else {
                // Iuran kematian / non-bulanan tetap seperti semula
                $single = $pembayaran->first();

                $iuran->status_bayar  = $iuran->sudah_bayar ? 'sudah_bayar' : 'belum_bayar';
                $iuran->tanggal_bayar = $single?->tanggal_bayar;
                $iuran->id_pembayaran = $single?->id;
            }

            unset($iuran->sudah_bayar);

            return $iuran;
        });

        return ApiResponse::success($data, 'Data informasi iuran berhasil diambil.');
    }

    public function show($id)
    {
        $iuran = InformasiIuran::with('penanggungJawab:nik,nama_warga')->find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($iuran, 'Detail informasi iuran berhasil diambil.');
    }
}
