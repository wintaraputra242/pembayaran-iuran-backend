<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Exports\LaporanPembayaranExport;
use Maatwebsite\Excel\Facades\Excel;

class LaporanController extends Controller
{
    /**
     * Export laporan pembayaran ke Excel
     */
    public function exportPembayaran(Request $request)
    {
        return Excel::download(
            new LaporanPembayaranExport($request),
            'laporan_pembayaran_' . now()->format('Ymd_His') . '.xlsx'
        );
    }
}
