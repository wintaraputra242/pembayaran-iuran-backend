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
        $type = $request->get('type', 'notifikasi');

        $totalWarga = Warga::count();

        $totalPembayaranHariIni = Pembayaran::whereDate('tanggal_bayar', now())
            ->whereIn('status_bayar', ['paid', 'manual'])
            ->sum('total_bayar');

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
                $data    = $this->getUnpaidResidents();
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
            'data'                      => $data,
        ], $message);
    }

    private function getNotifications()
    {
        $userId = Auth::id();

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
            ->where('status_bayar', 'paid')
            ->latest('tanggal_bayar')
            ->take(10)
            ->get();
    }

    private function getUnpaidResidents()
    {
        return Warga::whereDoesntHave('pembayaran', function ($q) {
            $q->where('status_bayar', 'paid');
        })
            ->select('nik', 'nama_warga', 'no_hp', 'status_keaktifan')
            ->where('status_keaktifan', 'aktif')
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
