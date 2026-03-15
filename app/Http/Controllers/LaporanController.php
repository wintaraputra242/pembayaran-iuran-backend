<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Exports\LaporanPembayaranExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Pembayaran;
use Illuminate\Support\Facades\DB;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class LaporanController extends Controller
{
    public function index(Request $request)
    {
        $query = Pembayaran::query()
            ->leftJoin('warga', 'pembayaran.nik', '=', 'warga.nik')
            ->leftJoin('anggota_regu', function ($join) {
                $join->on('warga.nik', '=', 'anggota_regu.nik')
                    ->whereNull('anggota_regu.deleted_at')
                    ->where('anggota_regu.status_keaktifan', 1);
            })
            ->leftJoin('regu', 'anggota_regu.id_regu', '=', 'regu.id')
            ->leftJoin('informasi_iuran', 'pembayaran.id_informasi_iuran', '=', 'informasi_iuran.id')
            ->leftJoin('users', 'pembayaran.processed_by', '=', 'users.id')
            ->select([
                'pembayaran.id',
                'pembayaran.transaction_id',
                'pembayaran.tanggal_bayar',
                'pembayaran.bulan',
                'pembayaran.metode_bayar',
                'pembayaran.status_bayar',
                'pembayaran.total_bayar',
                'pembayaran.bukti_pembayaran',

                DB::raw('COALESCE(pembayaran.nama_warga_snapshot, warga.nama_warga) as warga'),
                'regu.nama_regu as regu',
                'informasi_iuran.judul_iuran',
                'informasi_iuran.jenis_iuran',
                'users.name as petugas'
            ]);

        /**
         * FILTER TANGGAL
         */
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('tanggal_bayar', [
                $request->start_date,
                $request->end_date
            ]);
        }


        /**
         * FILTER METODE BAYAR
         */
        if ($request->filled('metode_bayar')) {
            $query->where('pembayaran.metode_bayar', $request->metode_bayar);
        }

        /**
         * FILTER STATUS
         */
        if ($request->filled('status_bayar')) {
            $query->where('pembayaran.status_bayar', $request->status_bayar);
        }

        /**
         * FILTER JENIS IURAN
         */
        if ($request->filled('jenis_iuran')) {
            $query->where('informasi_iuran.jenis_iuran', $request->jenis_iuran);
        }

        /**
         * FILTER REGU
         */
        if ($request->filled('regu')) {
            $query->where('anggota_regu.id_regu', $request->regu);
        }

        /**
         * FILTER NAMA WARGA
         */
        if ($request->filled('informasi_iuran')) {
            $query->where('pembayaran.id_informasi_iuran', $request->informasi_iuran);
        }

        $data = $query
            ->orderByDesc('pembayaran.tanggal_bayar')
            ->paginate($request->per_page ?? 10);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Export laporan pembayaran ke Excel
     */
    public function exportExcel(Request $request)
    {
        $filters = [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'jenis_iuran' => $request->jenis_iuran,
            'metode_bayar' => $request->metode_bayar,
            'status_bayar' => $request->status_bayar,
            'regu' => $request->regu,
            'informasi_iuran' => $request->informasi_iuran,
        ];

        $filename = 'laporan-pembayaran-' . now()->format('Y-m-d-His') . '.xlsx';

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'export',
            'description' => 'Mengunduh laporan pembayaran dalam format Excel.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return Excel::download(
            new LaporanPembayaranExport($filters),
            $filename
        );
    }
}
