<?php

namespace App\Http\Controllers\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\QrSetting;

class QrSettingController extends Controller
{
  public function active()
  {
    $qris = QrSetting::where('is_active', true)->latest()->first();

    if (!$qris) {
      return ApiResponse::error('QRIS tidak tersedia saat ini.', null, 404);
    }

    return ApiResponse::success([
      'image'          => asset('storage/' . $qris->image),
      'nama_rekening'  => $qris->nama_rekening,
      'nomor_rekening' => $qris->nomor_rekening,
      'keterangan'     => $qris->keterangan,
    ], 'success');
  }
}
