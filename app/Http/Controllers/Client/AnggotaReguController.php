<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\AnggotaRegu;
use Illuminate\Http\JsonResponse;

class AnggotaReguController extends Controller
{
    public function getAnggotaRegu(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $anggota = AnggotaRegu::where('nik', $warga->nik)
            ->where('status_keaktifan', 'aktif')
            ->with(['regu.ketuaRegu:id,name', 'regu.anggotaAktif.warga:nik,nama_warga,no_hp'])
            ->first();

        if (!$anggota) {
            return ApiResponse::error('Anda tidak terdaftar dalam regu manapun.', null, 404);
        }

        $regu = $anggota->regu;

        return ApiResponse::success([
            'regu' => [
                'id'               => $regu->id,
                'nama_regu'        => $regu->nama_regu,
                'status_keaktifan' => $regu->status_keaktifan,
                'ketua'            => [
                    'id'   => $regu->ketuaRegu?->id,
                    'name' => $regu->ketuaRegu?->name,
                ],
                'anggota' => $regu->anggotaAktif->map(fn($a) => [
                    'nik'              => $a->warga?->nik,
                    'nama_warga'       => $a->warga?->nama_warga,
                    'no_hp'            => $a->warga?->no_hp,
                    'is_leader'        => (bool) $a->is_leader,
                    'status_keaktifan' => $a->status_keaktifan,
                ]),
                'total_anggota' => $regu->anggotaAktif->count(),
            ],
        ], 'Data regu berhasil diambil.');
    }
}
