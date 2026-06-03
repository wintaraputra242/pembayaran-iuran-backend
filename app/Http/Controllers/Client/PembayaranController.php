<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use App\Models\AnggotaRegu;
use App\Models\InformasiIuran;
use App\Models\Notification;
use App\Models\Pembayaran;
use App\Models\User;
use App\Models\Warga;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Midtrans\Snap;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as NotificationMessaging;

class PembayaranController extends Controller
{
    public function payment(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'bulan'              => 'nullable|array',
            'bulan.*'            => 'integer|min:1|max:12',
            'note'               => 'nullable|string|max:500',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran tidak valid.',
            'bulan.array'                 => 'Bulan harus berupa array.',
            'bulan.*.integer'             => 'Nilai bulan harus berupa angka.',
            'bulan.*.min'                 => 'Bulan minimal 1.',
            'bulan.*.max'                 => 'Bulan maksimal 12.',
            'note.max'                    => 'Catatan maksimal 500 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data  = $validator->validated();
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if (!$iuran || !$iuran->status_aktif) {
            return ApiResponse::error('Informasi iuran tidak aktif.', null, 422);
        }

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        if ($iuran->jenis_iuran === 'kematian') {
            $data['bulan'] = null;
        }

        // Cek pembayaran existing
        $existingQuery = Pembayaran::where('nik', $warga->nik)
            ->where('id_informasi_iuran', $data['id_informasi_iuran']);

        $totalBayar = $iuran->jumlah_iuran;

        if ($iuran->jenis_iuran === 'bulanan' && !empty($data['bulan'])) {
            $totalBayar = $iuran->jumlah_iuran * count($data['bulan']);
            $existingQuery->where(function ($q) use ($data) {
                foreach ($data['bulan'] as $bulan) {
                    $q->orWhereJsonContains('bulan', (int) $bulan);
                }
            });
        }

        $existingPayment = $existingQuery->orderByDesc('created_at')->first();

        if ($existingPayment) {
            if ($existingPayment->status_bayar === 'paid') {
                return ApiResponse::error('Iuran sudah dibayar.', null, 409);
            }

            if (in_array($existingPayment->status_bayar, ['pending', 'waiting_payment'])) {
                $snapToken = json_decode($existingPayment->midtrans_raw_response, true)['snap_token'] ?? null;

                return ApiResponse::success([
                    'pembayaran' => $existingPayment,
                    'snap_token' => $snapToken,
                ], 'Melanjutkan pembayaran yang masih pending.');
            }
        }

        DB::beginTransaction();

        try {
            do {
                $transactionId = 'TRX-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
            } while (Pembayaran::where('transaction_id', $transactionId)->exists());

            $orderId = 'IURAN-' . Str::uuid();

            $pembayaran = Pembayaran::create([
                'transaction_id'        => $transactionId,
                'nik'                   => $warga->nik,
                'id_informasi_iuran'    => $data['id_informasi_iuran'],
                'nik_snapshot'          => $warga->nik,
                'nama_warga_snapshot'   => $warga->nama_warga,
                'jumlah_iuran_snapshot' => $iuran->jumlah_iuran ?? 0,
                'bulan'                 => $data['bulan'] ?? null,
                'tanggal_bayar'         => now()->toDateString(),
                'total_bayar'           => $totalBayar,
                'metode_bayar'          => 'transfer',
                'status_bayar'          => 'waiting_payment',
                'processed_by'          => null,
                'midtrans_order_id'     => $orderId,
                'note'                  => $data['note'] ?? null,
            ]);

            $params = [
                'transaction_details' => [
                    'order_id'     => $orderId,
                    'gross_amount' => (int) $totalBayar,
                ],
                'customer_details' => [
                    'first_name' => $warga->nama_warga,
                ],
                'enabled_payments' => ['bank_transfer'],
                'expiry'           => [
                    'unit'     => 'minutes',
                    'duration' => 30,
                ],
            ];

            $snapToken = Snap::getSnapToken($params);

            $pembayaran->update([
                'midtrans_raw_response' => json_encode(['snap_token' => $snapToken]),
            ]);

            $this->writeLog(
                'create',
                "Warga {$warga->nama_warga} (NIK: {$warga->nik}) membuat transaksi pembayaran ID {$pembayaran->id}.",
                $request
            );

            DB::commit();

            $this->sendPaymentNotification($warga, $pembayaran, $iuran);

            return ApiResponse::success([
                'pembayaran' => $pembayaran,
                'snap_token' => $snapToken,
            ], 'Transaksi pembayaran berhasil dibuat.', 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat menyimpan pembayaran.', $e->getMessage(), 500);
        }
    }

    public function getHistories(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $perPage = $request->get('per_page', 10);

        $query = Pembayaran::where('nik', $warga->nik)
            ->with(['informasiIuran:id,judul_iuran,jenis_iuran'])
            ->latest();

        if ($request->filled('status_bayar')) {
            $query->where('status_bayar', $request->status_bayar);
        }

        if ($request->filled('jenis_iuran')) {
            $query->whereHas('informasiIuran', function ($q) use ($request) {
                $q->where('jenis_iuran', $request->jenis_iuran);
            });
        }

        if ($request->filled('start_date')) {
            $query->whereDate('tanggal_bayar', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('tanggal_bayar', '<=', $request->end_date);
        }

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('transaction_id', 'like', "%{$keyword}%")
                    ->orWhereHas('informasiIuran', function ($sub) use ($keyword) {
                        $sub->where('judul_iuran', 'like', "%{$keyword}%");
                    });
            });
        }

        $data = $query->paginate($perPage);

        $data->getCollection()->transform(fn($pembayaran) => [
            'id'                    => $pembayaran->id,
            'transaction_id'        => $pembayaran->transaction_id,
            'judul_iuran'           => $pembayaran->informasiIuran?->judul_iuran,
            'jenis_iuran'           => $pembayaran->informasiIuran?->jenis_iuran,
            'jumlah_iuran_snapshot' => $pembayaran->jumlah_iuran_snapshot,
            'total_bayar'           => $pembayaran->total_bayar,
            'bulan'                 => $pembayaran->bulan,
            'tanggal_bayar'         => $pembayaran->tanggal_bayar,
            'metode_bayar'          => $pembayaran->metode_bayar,
            'status_bayar'          => $pembayaran->status_bayar,
            'note'                  => $pembayaran->note,
            'created_at'            => $pembayaran->created_at,
        ]);

        return ApiResponse::success($data, 'Riwayat pembayaran berhasil diambil.');
    }

    public function getPaidMonths(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $iuranBulanan = InformasiIuran::where('jenis_iuran', 'bulanan')
            ->where('status_aktif', true)
            ->get();

        $result = $iuranBulanan->map(function ($iuran) use ($warga) {
            $pembayaran = Pembayaran::where('nik', $warga->nik)
                ->where('id_informasi_iuran', $iuran->id)
                ->where('status_bayar', 'paid')
                ->get();

            $bulanSudahBayar = $pembayaran
                ->pluck('bulan')
                ->flatten()
                ->unique()
                ->sort()
                ->values();

            return [
                'id_informasi_iuran' => $iuran->id,
                'nama_iuran'         => $iuran->nama_iuran,
                'jumlah_iuran'       => $iuran->jumlah_iuran,
                'tahun'              => $iuran->tahun,
                'bulan_sudah_bayar'  => $bulanSudahBayar,
                'total_bulan_bayar'  => $bulanSudahBayar->count(),
            ];
        });

        return ApiResponse::success($result, 'Data bulan yang sudah dibayar berhasil diambil.');
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

    private function sendPaymentNotification(Warga $warga, Pembayaran $pembayaran, InformasiIuran $iuran): void
    {
        try {
            $admins = User::where('role', 'admin')
                ->where('is_active', true)
                ->with('devices')
                ->get();

            $ketuaRegu = AnggotaRegu::where('nik', $warga->nik)
                ->where('status_keaktifan', 'aktif')
                ->with(['regu.ketuaRegu.devices'])
                ->first()?->regu?->ketuaRegu;

            $targets = collect($admins);

            if ($ketuaRegu && !$targets->contains('id', $ketuaRegu->id)) {
                $targets->push($ketuaRegu);
            }

            $title = 'Pembayaran Baru';
            $body  = "{$warga->nama_warga} mengajukan pembayaran {$iuran->judul_iuran} sebesar Rp " . number_format($pembayaran->total_bayar, 0, ',', '.');

            foreach ($targets as $target) {
                $fcmTokens = $target->devices->pluck('fcm_token')->filter()->values()->toArray();

                if (empty($fcmTokens)) continue;

                $this->sendFcmNotification($fcmTokens, $title, $body);

                Notification::create([
                    'user_id' => $target->id,
                    'title'   => $title,
                    'message' => $body,
                    'type'    => 'pengingat',
                    'data'    => json_encode([
                        'pembayaran_id' => $pembayaran->id,
                        'nik'           => $warga->nik,
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Gagal kirim notifikasi pembayaran: ' . $e->getMessage());
        }
    }

    private function sendFcmNotification(array $tokens, string $title, string $body): void
    {
        (new \App\Services\FirebaseService())->sendBulk($tokens, $title, $body);
    }
}
