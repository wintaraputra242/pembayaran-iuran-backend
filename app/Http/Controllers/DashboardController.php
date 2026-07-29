<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Pembayaran;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $type  = $request->get('type', 'notifikasi');
        $tahun = now()->format('Y');
        $bulan = (int) now()->format('n');

        // Stats yang sudah ada
        $totalWarga = Warga::where('status_keaktifan', 'aktif')
            ->whereNull('deleted_at')
            ->count();

        $totalPembayaranHariIni = Pembayaran::whereDate('tanggal_bayar', now())
            ->whereIn('status_bayar', ['approved', 'pending'])
            ->sum('total_bayar');

        // Stats baru
        $iuranBulananAktif = \App\Models\InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('periode', $tahun)
            ->where('status_aktif', true)
            ->first();

        $sudahBayarBulanIni = $iuranBulananAktif
            ? Pembayaran::where('id_informasi_iuran', $iuranBulananAktif->id)
            ->where('status_bayar', 'approved')
            ->whereJsonContains('bulan', (string) $bulan)
            ->distinct('nik')
            ->count('nik')
            : 0;

        $belumBayarBulanIni = $totalWarga - $sudahBayarBulanIni;

        $iuranKematianAktif = \App\Models\InformasiIuran::where('jenis_iuran', 'kematian')
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->count();

        $pembayaranMenunggu = Pembayaran::where('status_bayar', 'pending')
            ->whereNull('deleted_at')
            ->count();

        $namaBulan = \Carbon\Carbon::create()->month($bulan)
            ->locale('id')
            ->translatedFormat('F');

        switch ($type) {
            case 'notifikasi':
                $data    = $this->getNotifications();
                $message = 'Data notifikasi dashboard.';
                break;
            case 'pembayaran':
                $data    = $this->getPayments();
                $message = 'Data pembayaran terbaru.';
                break;
            case 'warga_belum_bayar':
                $data    = $this->getUnpaidResidents($iuranBulananAktif, $bulan);
                $message = 'Data warga belum bayar.';
                break;
            case 'activity_log':
                $data    = $this->getActivityLogs();
                $message = 'Data aktivitas terbaru.';
                break;
            default:
                return ApiResponse::error('Tipe dashboard tidak valid.', null, 422);
        }

        return ApiResponse::success([
            'total_warga'               => $totalWarga,
            'total_pembayaran_hari_ini' => $totalPembayaranHariIni,
            'sudah_bayar_bulan_ini'     => $sudahBayarBulanIni,
            'belum_bayar_bulan_ini'     => $belumBayarBulanIni,
            'iuran_kematian_aktif'      => $iuranKematianAktif,
            'pembayaran_menunggu'       => $pembayaranMenunggu,
            'nama_bulan'                => $namaBulan,
            'tahun'                     => $tahun,
            'data'                      => $data,
        ], $message);
    }

    private function getNotifications()
    {
        $userId = Auth::user()->id;

        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->latest()
            ->take(5)
            ->get();
    }

    private function getPayments()
    {
        return Pembayaran::with([
            'warga:nik,nama_warga',
            'informasiIuran:id,judul_iuran',
        ])
            ->whereIn('status_bayar', ['approved', 'pending', 'rejected', 'cancelled'])
            ->latest('tanggal_bayar')
            ->take(10)
            ->get();
    }

    private function getUnpaidResidents($iuranBulananAktif, int $bulan)
    {
        if (!$iuranBulananAktif) return [];

        $tahunPeriode = (int) $iuranBulananAktif->periode;

        $sudahBayarNik = Pembayaran::where('id_informasi_iuran', $iuranBulananAktif->id)
            ->whereIn('status_bayar', ['approved', 'pending'])
            ->whereJsonContains('bulan', (string) $bulan)
            ->pluck('nik')
            ->toArray();

        return Warga::whereNotIn('nik', $sudahBayarNik)
            ->whereNull('deleted_at')
            ->select('nik', 'nama_warga', 'no_hp', 'status_keaktifan', 'tanggal_nonaktif', 'created_at')
            ->where(function ($q) use ($bulan, $tahunPeriode) {
                // Warga aktif yang sudah wajib bayar di bulan ini
                $q->where(function ($q1) use ($bulan, $tahunPeriode) {
                    $q1->where('status_keaktifan', 'aktif')
                        ->where(function ($q2) use ($bulan, $tahunPeriode) {
                            $q2->whereYear('created_at', '<', $tahunPeriode)
                                ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
                                    $q3->whereYear('created_at', $tahunPeriode)
                                        ->whereMonth('created_at', '<=', $bulan);
                                });
                        });
                })
                    // Warga nonaktif yang masih punya tunggakan
                    ->orWhere(function ($q1) use ($bulan, $tahunPeriode) {
                        $q1->where('status_keaktifan', 'tidak_aktif')
                            ->whereNotNull('tanggal_nonaktif')
                            ->where(function ($q2) use ($bulan, $tahunPeriode) {
                                $q2->whereYear('tanggal_nonaktif', '>', $tahunPeriode)
                                    ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
                                        $q3->whereYear('tanggal_nonaktif', $tahunPeriode)
                                            ->whereMonth('tanggal_nonaktif', '>', $bulan);
                                    });
                            });
                    });
            })
            ->orderBy('nama_warga')
            ->take(10)
            ->get();
    }

    private function getActivityLogs()
    {
        return ActivityLog::with(['user:id,name'])
            ->latest()
            ->take(10)
            ->get();
    }
}
