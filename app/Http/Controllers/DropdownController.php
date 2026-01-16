<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DropdownController extends Controller
{
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
}
