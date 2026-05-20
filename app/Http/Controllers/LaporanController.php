<?php

namespace App\Http\Controllers;

use App\Exports\LaporanPembayaranExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

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
            ->whereNull('pembayaran.deleted_at')
            ->select([
                'pembayaran.id',
                'pembayaran.transaction_id',
                'pembayaran.tanggal_bayar',
                'pembayaran.bulan',
                'pembayaran.metode_bayar',
                'pembayaran.status_bayar',
                'pembayaran.total_bayar',
                'pembayaran.bukti_pembayaran',
                DB::raw('COALESCE(pembayaran.nama_warga_snapshot, warga.nama_warga) as nama_warga'),
                'regu.nama_regu as regu',
                'informasi_iuran.judul_iuran',
                'informasi_iuran.jenis_iuran',
                'users.name as petugas',
            ]);

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('pembayaran.tanggal_bayar', [
                $request->start_date,
                $request->end_date,
            ]);
        }

        if ($request->filled('metode_bayar')) {
            $query->where('pembayaran.metode_bayar', $request->metode_bayar);
        }

        if ($request->filled('status_bayar')) {
            $query->where('pembayaran.status_bayar', $request->status_bayar);
        }

        if ($request->filled('jenis_iuran')) {
            $query->where('informasi_iuran.jenis_iuran', $request->jenis_iuran);
        }

        if ($request->filled('regu')) {
            $query->where('anggota_regu.id_regu', $request->regu);
        }

        if ($request->filled('informasi_iuran')) {
            $query->where('pembayaran.id_informasi_iuran', $request->informasi_iuran);
        }

        $data = $query
            ->orderByDesc('pembayaran.tanggal_bayar')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($data, 'Data laporan berhasil diambil.');
    }

    public function exportExcel(Request $request)
    {
        $filters = [
            'start_date'      => $request->start_date,
            'end_date'        => $request->end_date,
            'jenis_iuran'     => $request->jenis_iuran,
            'metode_bayar'    => $request->metode_bayar,
            'status_bayar'    => $request->status_bayar,
            'regu'            => $request->regu,
            'informasi_iuran' => $request->informasi_iuran,
        ];

        $filename = 'laporan-pembayaran-' . now()->format('Y-m-d-His') . '.xlsx';

        $this->writeLog(
            'export',
            "Mengunduh laporan pembayaran dalam format Excel.",
            $request
        );

        return Excel::download(new LaporanPembayaranExport($filters), $filename);
    }

    private function writeLog(string $action, string $description, Request $request): void
    {
        try {
            ActivityLog::create([
                'id_user'            => Auth::id(),
                'nama_user_snapshot' => Auth::user()?->name,
                'action'             => $action,
                'description'        => $description,
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Gagal menulis activity log: {$e->getMessage()}");
        }
    }
}
