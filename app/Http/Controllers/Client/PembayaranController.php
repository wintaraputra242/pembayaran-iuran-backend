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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Illuminate\Support\Str;

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
            'metode_bayar'       => 'required|in:transfer,qris',
            'bukti_pembayaran'   => 'required|image|mimes:jpg,jpeg,png|max:5000',
            'note'               => 'nullable|string|max:500',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran tidak valid.',
            'bulan.array'                 => 'Bulan harus berupa array.',
            'bulan.*.integer'             => 'Nilai bulan harus berupa angka.',
            'bulan.*.min'                 => 'Bulan minimal 1.',
            'bulan.*.max'                 => 'Bulan maksimal 12.',
            'metode_bayar.required'       => 'Metode pembayaran wajib diisi.',
            'metode_bayar.in'             => 'Metode bayar harus salah satu dari: transfer atau qris.',
            'bukti_pembayaran.required'   => 'Bukti pembayaran wajib diunggah.',
            'bukti_pembayaran.image'      => 'Bukti pembayaran harus berupa gambar.',
            'bukti_pembayaran.mimes'      => 'Bukti pembayaran harus berformat jpg, jpeg, atau png.',
            'bukti_pembayaran.max'        => 'Ukuran bukti pembayaran maksimal 5MB.',
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

        $totalBayar    = $iuran->jumlah_iuran;
        $existingQuery = Pembayaran::where('nik', $warga->nik)
            ->where('id_informasi_iuran', $data['id_informasi_iuran']);

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
            if ($existingPayment->status_bayar === 'approved') {
                return ApiResponse::error('Iuran sudah dibayar.', null, 409);
            }

            if ($existingPayment->status_bayar === 'pending') {
                return ApiResponse::success([
                    'pembayaran' => $existingPayment,
                ], 'Pembayaran sebelumnya masih menunggu validasi pengurus.');
            }
        }

        DB::beginTransaction();

        try {
            $file     = $request->file('bukti_pembayaran');
            $filename = 'bukti-' . time() . '-' . Str::random(6) . '.jpg';

            $manager = new ImageManager(new Driver());
            $image   = $manager->read($file)->toJpeg(75);

            Storage::disk('public')->put('bukti-pembayaran/' . $filename, (string) $image);

            $pembayaran = Pembayaran::create([
                'nik'                   => $warga->nik,
                'id_informasi_iuran'    => $data['id_informasi_iuran'],
                'nik_snapshot'          => $warga->nik,
                'nama_warga_snapshot'   => $warga->nama_warga,
                'jumlah_iuran_snapshot' => $iuran->jumlah_iuran ?? 0,
                'bulan'                 => $data['bulan'] ?? null,
                'tanggal_bayar'         => now()->toDateString(),
                'total_bayar'           => $totalBayar,
                'metode_bayar'          => $data['metode_bayar'],
                'status_bayar'          => 'pending',
                'submitted_at'          => now(),
                'processed_by'          => null,
                'bukti_pembayaran'      => 'bukti-pembayaran/' . $filename,
                'note'                  => $data['note'] ?? null,
            ]);

            $this->writeLog(
                'create',
                "Warga {$warga->nama_warga} (NIK: {$warga->nik}) mengajukan pembayaran ID {$pembayaran->id} via {$data['metode_bayar']}.",
                $request
            );

            DB::commit();

            $this->sendPaymentNotification($warga, $pembayaran, $iuran);

            return ApiResponse::success([
                'pembayaran' => $pembayaran,
            ], 'Bukti pembayaran berhasil dikirim. Menunggu validasi pengurus.', 201);
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
            'rejection_reason'     => $pembayaran->rejection_reason,
            'bukti_bayar'           => asset('storage/' . $pembayaran->bukti_pembayaran),
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
                ->whereIn('status_bayar', ['approved', 'pending'])
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

                // Kirim push kalau ada device — tapi TIDAK "continue"/skip
                // seluruh proses kalau tidak ada. Riwayat notif in-app tetap
                // harus tersimpan supaya bisa dilihat kapan pun target buka app.
                if (!empty($fcmTokens)) {
                    $this->sendFcmNotification($fcmTokens, $title, $body);
                }

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
