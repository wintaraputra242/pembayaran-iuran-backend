<?php

namespace App\Http\Controllers;

use App\Exports\LaporanPembayaranExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\InformasiIuran;
use App\Models\Warga;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            ->leftJoin('users as petugas_user', 'pembayaran.processed_by', '=', 'petugas_user.id')
            ->leftJoin('users as validator_user', 'pembayaran.validated_by', '=', 'validator_user.id')
            ->whereNull('pembayaran.deleted_at')
            ->select([
                'pembayaran.id',
                'pembayaran.tanggal_bayar',
                'pembayaran.submitted_at',
                'pembayaran.validated_at',
                'pembayaran.bulan',
                'pembayaran.metode_bayar',
                'pembayaran.status_bayar',
                'pembayaran.total_bayar',
                'pembayaran.jumlah_iuran_snapshot',
                'pembayaran.bukti_pembayaran',
                'pembayaran.rejection_reason',
                'pembayaran.note',
                DB::raw('COALESCE(pembayaran.nama_warga_snapshot, warga.nama_warga) as nama_warga'),
                DB::raw('COALESCE(pembayaran.nik_snapshot, pembayaran.nik) as nik'),
                'regu.nama_regu as regu',
                'regu.id as regu_id',
                'informasi_iuran.judul_iuran',
                'informasi_iuran.jenis_iuran',
                'petugas_user.name as petugas',
                'validator_user.name as divalidasi_oleh',
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
            $query->where('regu.id', $request->regu);
        }

        if ($request->filled('informasi_iuran')) {
            $query->where('pembayaran.id_informasi_iuran', $request->informasi_iuran);
        }

        // Filter keyword nama warga
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('pembayaran.nama_warga_snapshot', 'like', '%' . $request->keyword . '%')
                    ->orWhere('warga.nama_warga', 'like', '%' . $request->keyword . '%')
                    ->orWhere('pembayaran.nik_snapshot', 'like', '%' . $request->keyword . '%');
            });
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

    public function exportPdf(Request $request)
    {
        $hasIdInformasiIuran = $request->filled('id_informasi_iuran');
        $hasRentangKematian  = $request->filled('jenis_iuran')
            && $request->filled('start_date')
            && $request->filled('end_date');

        if (!$hasIdInformasiIuran && !$hasRentangKematian) {
            return ApiResponse::error(
                'Wajib mengisi id_informasi_iuran, atau jenis_iuran beserta start_date dan end_date untuk laporan gabungan iuran kematian.',
                null,
                422
            );
        }

        if ($hasIdInformasiIuran) {
            $request->validate([
                'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            ], [
                'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
                'id_informasi_iuran.exists'   => 'Informasi iuran tidak ditemukan.',
            ]);

            $iuran = InformasiIuran::findOrFail($request->id_informasi_iuran);

            $this->writeLog(
                'export',
                "Mengunduh laporan {$iuran->jenis_iuran} '{$iuran->judul_iuran}' dalam format PDF.",
                $request
            );

            if ($iuran->jenis_iuran === 'bulanan') {
                return $this->exportBulanan($iuran);
            }

            return $this->exportKematian($iuran);
        }

        $request->validate([
            'jenis_iuran' => 'required|in:kematian',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
        ], [
            'jenis_iuran.required'    => 'Jenis iuran wajib diisi.',
            'jenis_iuran.in'          => 'Laporan gabungan berdasarkan rentang tanggal hanya tersedia untuk jenis iuran kematian.',
            'start_date.required'     => 'Tanggal mulai wajib diisi.',
            'start_date.date'         => 'Tanggal mulai tidak valid.',
            'end_date.required'       => 'Tanggal akhir wajib diisi.',
            'end_date.date'           => 'Tanggal akhir tidak valid.',
            'end_date.after_or_equal' => 'Tanggal akhir harus setelah atau sama dengan tanggal mulai.',
        ]);

        $this->writeLog(
            'export',
            "Mengunduh laporan gabungan iuran kematian periode {$request->start_date} s/d {$request->end_date} dalam format PDF.",
            $request
        );

        return $this->exportKematianRentang($request->start_date, $request->end_date);
    }

    // ─── Export Bulanan ───────────────────────────────────────────────────────────

    private function exportBulanan(InformasiIuran $iuran)
    {
        // Ambil semua warga aktif
        $wargas = Warga::with([
            'anggotaRegu' => fn($q) => $q->whereNull('deleted_at')
                ->where('status_keaktifan', 'aktif')
                ->with('regu'),
        ])
            ->whereNull('deleted_at')
            ->select('nik', 'nama_warga')
            ->orderBy('nama_warga')
            ->get();

        // Ambil semua pembayaran approved untuk iuran ini
        $pembayarans = Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->whereIn('status_bayar', ['approved'])
            ->whereNotNull('bulan')
            ->get()
            ->keyBy('nik'); // key by nik untuk lookup cepat

        $data = $wargas->map(function ($warga) use ($pembayarans) {
            $bayar = $pembayarans->get($warga->nik);

            $bulanDibayar = [];
            if ($bayar) {
                $bulan = $bayar->bulan;
                if (is_string($bulan)) {
                    $bulan = json_decode($bulan, true) ?? [];
                }
                $bulanDibayar = collect($bulan)->map(fn($b) => (int) $b)->toArray();
            }

            $anggotaAktif = $warga->anggotaRegu->first();

            return [
                'nama_warga'   => $warga->nama_warga,
                'regu'         => $anggotaAktif?->regu?->nama_regu ?? '-',
                'bulan_dibayar' => $bulanDibayar,
            ];
        })->values()->toArray();

        $bulanHeaders = [
            'Jan',
            'Feb',
            'Mar',
            'Apr',
            'Mei',
            'Jun',
            'Jul',
            'Agt',
            'Sep',
            'Okt',
            'Nov',
            'Des',
        ];

        $pdf = Pdf::loadView('exports.laporan-bulanan', [
            'iuran'        => $iuran,
            'wargas'       => $data,
            'bulanHeaders' => $bulanHeaders,
        ])->setPaper('a4', 'landscape');

        $filename = 'laporan-bulanan-' . Str::slug($iuran->judul_iuran) . '-' . now()->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }

    // ─── Export Kematian ──────────────────────────────────────────────────────────

    private function exportKematian(InformasiIuran $iuran)
    {
        // Ambil semua warga aktif
        $wargas = Warga::with([
            'anggotaRegu' => fn($q) => $q->whereNull('deleted_at')
                ->where('status_keaktifan', 'aktif')
                ->with('regu'),
        ])
            ->whereNull('deleted_at')
            ->select('nik', 'nama_warga')
            ->orderBy('nama_warga')
            ->get();

        // NIK yang sudah bayar (approved)
        $sudahBayarNiks = Pembayaran::where('id_informasi_iuran', $iuran->id)
            ->whereIn('status_bayar', ['approved'])
            ->pluck('nik')
            ->unique()
            ->toArray();

        $data = $wargas->map(function ($warga) use ($sudahBayarNiks) {
            $anggotaAktif = $warga->anggotaRegu->first();

            return [
                'nama_warga'  => $warga->nama_warga,
                'regu'        => $anggotaAktif?->regu?->nama_regu ?? '-',
                'sudah_bayar' => in_array($warga->nik, $sudahBayarNiks),
            ];
        })->values()->toArray();

        $pdf = Pdf::loadView('exports.laporan-kematian', [
            'iuran'  => $iuran,
            'wargas' => $data,
        ])->setPaper('a4', 'portrait');

        $filename = 'laporan-kematian-' . Str::slug($iuran->judul_iuran) . '-' . now()->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }

    // ─── Export Kematian (gabungan berdasarkan rentang tanggal) ─────────────────────

    private function exportKematianRentang(string $startDate, string $endDate)
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end   = Carbon::parse($endDate)->endOfDay();

        // Semua informasi_iuran kematian yang dibuat dalam rentang tanggal ini
        $iurans = InformasiIuran::where('jenis_iuran', 'kematian')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get();

        // Ambil semua warga aktif sekali saja, dipakai ulang untuk tiap kelompok iuran
        $wargas = Warga::with([
            'anggotaRegu' => fn($q) => $q->whereNull('deleted_at')
                ->where('status_keaktifan', 'aktif')
                ->with('regu'),
        ])
            ->whereNull('deleted_at')
            ->select('nik', 'nama_warga')
            ->orderBy('nama_warga')
            ->get();

        // NIK yang sudah bayar (approved), dikelompokkan per informasi_iuran
        $sudahBayarPerIuran = Pembayaran::whereIn('id_informasi_iuran', $iurans->pluck('id'))
            ->whereIn('status_bayar', ['approved'])
            ->get()
            ->groupBy('id_informasi_iuran')
            ->map(fn($rows) => $rows->pluck('nik')->unique()->toArray());

        // Susun 1 baris per warga, 1 kolom per informasi_iuran kematian (mirip pola laporan bulanan)
        $data = $wargas->map(function ($warga) use ($iurans, $sudahBayarPerIuran) {
            $anggotaAktif = $warga->anggotaRegu->first();

            $statusPerIuran = $iurans->mapWithKeys(function ($iuran) use ($warga, $sudahBayarPerIuran) {
                $niks = $sudahBayarPerIuran->get($iuran->id, []);

                return [$iuran->id => in_array($warga->nik, $niks)];
            })->toArray();

            return [
                'nama_warga'       => $warga->nama_warga,
                'regu'             => $anggotaAktif?->regu?->nama_regu ?? '-',
                'status_per_iuran' => $statusPerIuran,
                'jumlah_bayar'     => collect($statusPerIuran)->filter()->count(),
            ];
        })->values()->toArray();

        $pdf = Pdf::loadView('exports.laporan-kematian-rentang', [
            'iurans'    => $iurans,
            'wargas'    => $data,
            'startDate' => $start,
            'endDate'   => $end,
        ])->setPaper('a4', 'landscape');

        $filename = 'laporan-kematian-' . $start->format('Ymd') . '-' . $end->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }

    private function writeLog(string $action, string $description, Request $request): void
    {
        try {
            ActivityLog::create([
                'id_user'            => Auth::user()?->id,
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
