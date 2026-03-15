<?php

namespace App\Http\Controllers;

use App\Models\Pembayaran;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\InformasiIuran;
use App\Models\Warga;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Midtrans\Snap;
use Midtrans\Notification;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Storage;
use App\Models\ActivityLog;
use App\Models\Notification as NotificationModel;

class PembayaranController extends Controller
{
    public function index(Request $request)
    {
        $query = Pembayaran::with([
            'warga.anggotaRegu.regu',
            'informasiIuran',
            'processedBy:id,name,role'
        ]);

        if ($request->filled('nama_warga')) {
            $query->whereHas('warga', function ($q) use ($request) {
                $q->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
            });
        }

        if ($request->filled('regu')) {
            $query->whereHas('warga.anggotaRegu.regu', function ($q) use ($request) {
                $q->where('id', $request->regu);
            });
        }

        if ($request->filled('jenis_iuran')) {
            $query->whereHas('informasiIuran', function ($q) use ($request) {
                $q->where('jenis_iuran', $request->jenis_iuran);
            });
        }

        if ($request->filled('metode_bayar')) {
            $query->where('metode_bayar', $request->metode_bayar);
        }

        if ($request->filled('status')) {
            $query->where('status_bayar', $request->status);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('tanggal_bayar', [
                $request->start_date,
                $request->end_date
            ]);
        }

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('tanggal_bayar')
            ->paginate($perPage);

        return ApiResponse::success(
            $data,
            'Data pembayaran berhasil diambil.'
        );
    }

    public function show($id)
    {
        $pembayaran = Pembayaran::with([
            'warga',
            'informasiIuran'
        ])->find($id);

        if (!$pembayaran) {
            return ApiResponse::error(
                'Data pembayaran tidak ditemukan.',
                null,
                404
            );
        }

        return ApiResponse::success(
            $pembayaran,
            'Detail pembayaran berhasil diambil.'
        );
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validator = Validator::make($request->all(), [
                'nik' => 'required|exists:warga,nik',
                'id_informasi_iuran' => 'required|exists:informasi_iuran,id',

                'tanggal_bayar' => 'required|date',
                'total_bayar' => 'required|numeric|min:0',

                'bulan' => 'nullable|array',
                'bulan.*' => 'min:1|max:12',
                'metode_bayar' => 'required|in:tunai,transfer,qris',
                'bukti_pembayaran' => 'nullable',
            ], [
                'nik.required' => 'NIK wajib diisi.',
                'nik.exists' => 'NIK tidak ditemukan dalam data warga.',

                'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
                'id_informasi_iuran.exists' => 'Informasi iuran tidak valid.',

                'tanggal_bayar.required' => 'Tanggal bayar wajib diisi.',
                'tanggal_bayar.date' => 'Tanggal bayar harus berupa tanggal yang valid.',

                'total_bayar.required' => 'Total bayar wajib diisi.',
                'total_bayar.numeric' => 'Total bayar harus berupa angka.',
                'total_bayar.min' => 'Total bayar minimal bernilai 0.',

                'bulan.array' => 'Bulan harus berupa array.',
                'bulan.*.min' => 'Bulan minimal 1.',
                'bulan.*.max' => 'Bulan maksimal 12.',

                'metode_bayar.required' => 'Metode Pembayaran wajib diisi.',
                'metode_bayar.in' => 'Metode bayar harus salah satu dari: tunai, transfer, atau qris.',
            ]);

            if ($validator->fails()) {
                return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
            }

            $data = $validator->validated();

            $iuran = InformasiIuran::findOrFail($data['id_informasi_iuran']);

            if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
                return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
            }

            if ($iuran->jenis_iuran === 'kematian') {
                $data['bulan'] = null;
            }

            $existingPayment = Pembayaran::where('nik', $data['nik'])
                ->where('id_informasi_iuran', $data['id_informasi_iuran'])
                ->when($iuran->jenis_iuran === 'bulanan', function ($q) use ($data) {
                    $q->where('bulan', $data['bulan']);
                })
                ->orderByDesc('created_at')
                ->first();

            if ($existingPayment) {
                if ($existingPayment->status_bayar === 'paid') {
                    return ApiResponse::error(
                        'Iuran sudah dibayar.',
                        null,
                        409
                    );
                }

                if ($existingPayment->status_bayar === 'pending') {

                    $snapToken = json_decode($existingPayment->midtrans_raw_response, true)['snap_token'] ?? null;

                    return ApiResponse::success(
                        [
                            'pembayaran' => $existingPayment,
                            'snap_token' => $snapToken
                        ],
                        'Melanjutkan pembayaran yang masih pending.',
                        200
                    );
                }
            }

            $warga = Warga::where('nik', $data['nik'])->firstOrFail();

            $orderId = 'IURAN-' . Str::uuid();

            $statusBayar = 'pending';

            $pathBukti = null;

            if ($data['metode_bayar'] === 'tunai') {
                if ($request->hasFile('bukti_pembayaran')) {

                    $file = $request->file('bukti_pembayaran');

                    // Buat nama file unik
                    $filename = 'bukti-' . time() . '.jpg';

                    $manager = new ImageManager(new Driver());

                    // Resize & compress
                    $image = $manager->read($file)
                        ->toJpeg(75); // 75 = compression quality

                    Storage::disk('public')->put(
                        'bukti-pembayaran/' . $filename,
                        (string) $image
                    );

                    $pathBukti = 'bukti-pembayaran/' . $filename;
                }

                $statusBayar = 'paid';
            }

            do {
                $transactionId = 'TRX-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
            } while (Pembayaran::where('transaction_id', $transactionId)->exists());

            $pembayaran = Pembayaran::create([
                'transaction_id' => $transactionId,
                
                'nik' => $data['nik'],
                'nik_snapshot' => $warga->nik,
                'nama_warga_snapshot' => $warga->nama,
                'jumlah_iuran_snapshot' => $iuran->jumlah_iuran ?? 0,
                'bulan' => $data['bulan'] ?? null,
                'id_informasi_iuran' => $data['id_informasi_iuran'],
                'tanggal_bayar' => $data['tanggal_bayar'],
                'total_bayar' => $data['total_bayar'],
                'metode_bayar' => $data['metode_bayar'] ?? null,
                'status_bayar' => $statusBayar,

                'processed_by' => Auth::id(),

                'midtrans_order_id' => $data['metode_bayar'] !== 'tunai' ? $orderId : null,
                'midtrans_transaction_id' => null,
                'midtrans_va_number' => null,
                'midtrans_qr_string' => null,
                'midtrans_payment_type' => null,
                'midtrans_raw_response' => null,
                'bukti_pembayaran' => $pathBukti ?? null,
            ]);

            $snapToken = null;

            if (in_array($data['metode_bayar'], ['transfer', 'qris'])) {

                $params = [
                    'transaction_details' => [
                        'order_id' => $orderId,
                        'gross_amount' => $data['total_bayar'],
                    ],
                    'customer_details' => [
                        'first_name' => $warga->nama,
                    ],
                    'expiry' => [
                        'unit' => 'minutes',
                        'duration' => 30
                    ]
                ];

                // 🔥 Force QRIS
                if ($data['metode_bayar'] === 'qris') {
                    $params['enabled_payments'] = ['other_qris'];
                }

                // 🔥 Force Bank Transfer
                if ($data['metode_bayar'] === 'transfer') {
                    $params['enabled_payments'] = ['bank_transfer'];
                }

                $snapToken = Snap::getSnapToken($params);

                $pembayaran->update([
                    'midtrans_raw_response' => json_encode([
                        'snap_token' => $snapToken
                    ])
                ]);
            }

            DB::commit();

            ActivityLog::create([
                'id_user' => Auth::id(),
                'action' => 'create',
                'description' => 'Membuat transaksi pembayaran iuran.',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return ApiResponse::success(
                [
                    'pembayaran' => $pembayaran,
                    'snap_token' => $snapToken
                ],
                'Transaksi pembayaran berhasil.',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error(
                'Terjadi kesalahan saat menyimpan pembayaran.',
                $e->getMessage(),
                500
            );
        }
        
    }

    public function wargaUnpaidPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'bulan' => 'nullable|integer|max:12',
            'nama_warga' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $iuran = InformasiIuran::findOrFail($data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        $query = Warga::with([
            'anggotaRegu.regu'
        ])->whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
            $q->where('id_informasi_iuran', $data['id_informasi_iuran']);

            if ($iuran->jenis_iuran === 'bulanan') {
                $q->whereJsonContains('bulan', (int) $data['bulan']);
            }
        });

        // 🔥 Tambahan filter nama_warga
        if ($request->filled('nama_warga')) {
            $query->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
        }

        $perPage = $request->get('per_page', 10);

        $warga = $query
            ->orderBy('nama_warga')
            ->paginate($perPage);

        return ApiResponse::success(
            $warga,
            'Daftar warga yang belum melakukan pembayaran berhasil diambil.'
        );
    }

    // ========================================
    // NOTIFIKASI UNTUK HALAMAN CEK BELUM BAYAR
    public function sendUnpaidResidentsNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $iuran = InformasiIuran::findOrFail($data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['month'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        $wargaList = Warga::whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
                $q->where('id_informasi_iuran', $data['id_informasi_iuran']);

                if ($iuran->jenis_iuran === 'bulanan') {
                    $q->where('bulan', $data['month']);
                }
            })
            ->with('users.devices')
            ->get();

        $tokens = $wargaList
            ->flatMap(fn ($warga) => $warga->users?->devices?->pluck('fcm_token') ?? [])
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($tokens)) {
            return ApiResponse::success(null, 'Tidak ada warga yang belum bayar.');
        }

        $firebase = new \App\Services\FirebaseService();

        $title = 'Pengingat Pembayaran Iuran';
        $message = 'Anda belum melakukan pembayaran untuk iuran ' . $iuran->nama_iuran;

        $firebase->sendBulk($tokens, $title, $message);

        // simpan notifikasi ke database
        $notifications = $wargaList->map(function ($warga) use ($title, $message) {
            return [
                'title' => $title,
                'message' => $message,
                'type' => 'reminder',
                'user_id' => $warga->users?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->filter()->values()->toArray();

        NotificationModel::insert($notifications);

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'send_notification',
            'description' => 'Mengirim notifikasi pengingat pembayaran kepada seluruh warga yang belum membayar.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Notifikasi berhasil dikirim ke warga yang belum bayar.'
        );
    }

    public function sendResidentNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $warga = Warga::with('users.devices')->findOrFail($data['nik']);

        $tokens = $warga->users?->devices?->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        $firebase = new \App\Services\FirebaseService();

        $firebase->sendBulk(
            $tokens,
            $data['title'],
            $data['message']
        );

        NotificationModel::create([
            'title' => $data['title'],
            'message' => $data['message'],
            'type' => 'manual',
            'user_id' => $warga->users?->id,
        ]);

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'send_notification',
            'description' => 'Mengirim notifikasi pengingat pembayaran kepada satu warga.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Notifikasi berhasil dikirim ke warga.'
        );
    }
    // ========================================

    public function historyAlreadyPaid(Request $request)
    {
        $query = Pembayaran::with([
            'warga.anggotaRegu.regu',
            'informasiIuran',
            'processedBy:id,name,role'
        ])->where('nik', $request->nik)
        ->where('status_bayar', 'paid');

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('tanggal_bayar')
            ->paginate($perPage);

        return ApiResponse::success(
            $data,
            'Data riwayat pembayaran berhasil diambil.'
        );
    }

    public function historyNotYetPaid(Request $request)
    {
        $nik = $request->nik;

        $iuranSudahDibayar = Pembayaran::where('nik', $nik)
            ->where('status_bayar', 'paid')
            ->pluck('id_informasi_iuran');

        $query = InformasiIuran::with([
            'pembayaran' // sesuaikan jika ada relasi lain
        ])->whereNotIn('id', $iuranSudahDibayar);

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::success(
            $data,
            'Data iuran yang belum dibayar berhasil diambil.'
        );
    }

    // ========================================
    // NOTIFIKASI UNTUK HALAMAN RIWAYAT PEMBAYARAN WARGA
    public function sendAllUnpaidToResident(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::with('users.devices')->findOrFail($request->nik);

        // Ambil iuran yang belum dibayar oleh warga ini
        $paidIuranIds = Pembayaran::where('warga_id', $warga->nik)
            ->where('status_bayar', 'paid')
            ->pluck('id_informasi_iuran');

        $unpaidList = InformasiIuran::whereNotIn('id', $paidIuranIds)->get();

        if ($unpaidList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada iuran yang belum dibayar.');
        }

        $tokens = $warga->users?->devices?->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        // Susun pesan ringkasan
        $message = "Anda memiliki {$unpaidList->count()} iuran yang belum dibayar:\n\n";

        foreach ($unpaidList as $item) {
            $message .= "- {$item->judul_iuran}\n";
        }

        $message .= "\nSegera lakukan pembayaran.";

        $firebase = new \App\Services\FirebaseService();

        $firebase->sendBulk(
            $tokens,
            'Pengingat Iuran Belum Dibayar',
            $message
        );

        NotificationModel::create([
            'title' => 'Pengingat Iuran Belum Dibayar',
            'message' => $message,
            'type' => 'reminder',
            'user_id' => $warga->users?->id,
        ]);

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'send_notification',
            'description' => 'Mengirim seluruh tagihan iuran yang belum dibayar kepada warga.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Notifikasi ringkasan berhasil dikirim.'
        );
    }

    public function sendUnpaidOneByOneToResident(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::with('users.devices')->findOrFail($request->nik);

        $paidIuranIds = Pembayaran::where('warga_id', $warga->nik)
            ->where('status_bayar', 'paid')
            ->pluck('id_informasi_iuran');

        $unpaidList = InformasiIuran::whereNotIn('id', $paidIuranIds)->get();

        if ($unpaidList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada iuran yang belum dibayar.');
        }

        $tokens = $warga->users?->devices?->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        $firebase = new \App\Services\FirebaseService();

        foreach ($unpaidList as $item) {
            $title = 'Pengingat Iuran';
            $message = "Iuran {$item->judul_iuran} belum dibayar. Segera lakukan pembayaran.";

            $firebase->sendBulk(
                $tokens,
                $title,
                $message
            );

            NotificationModel::create([
                'title' => $title,
                'message' => $message,
                'type' => 'reminder',
                'user_id' => $warga->users?->id,
                'data' => [
                    'id_informasi_iuran' => $item->id
                ]
            ]);

        }

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'send_notification',
            'description' => 'Mengirim tagihan iuran yang belum dibayar kepada warga secara satu per satu.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Notifikasi iuran dikirim satu per satu.'
        );
    }
    // ========================================

    public function getPaidMonth(Request $request)
    {
        $request->validate([
            'id_informasi_iuran' => 'required|integer',
            'nik' => 'required|string',
        ]);

        $paidMonths = Pembayaran::where('id_informasi_iuran', $request->id_informasi_iuran)
            ->where('nik', $request->nik)
            ->where('status_bayar', 'paid')
            ->pluck('bulan')
            ->flatten()
            ->unique()
            ->values();

        return ApiResponse::success(
            $paidMonths,
            'Data bulan yang sudah dibayar berhasil diambil.',
            200
        );
    }

    public function handleNotification(Request $request)
    {
        try {
            DB::beginTransaction();

            $notification = new Notification();

            $transactionStatus = $notification->transaction_status;
            $paymentType = $notification->payment_type;
            $orderId = $notification->order_id;
            $transactionId = $notification->transaction_id;
            $vaNumbers = $notification->va_numbers ?? null;
            $qrString = $notification->qr_string ?? null;
            $fraudStatus = $notification->fraud_status ?? null;

            $pembayaran = Pembayaran::where('midtrans_order_id', $orderId)->first();

            if (!$pembayaran) {
                return response()->json(['message' => 'Order tidak ditemukan.'], 404);
            }

            // Tentukan status bayar
            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'challenge') {
                    $pembayaran->status_bayar = 'pending';
                } else {
                    $pembayaran->status_bayar = 'paid';
                }
            } elseif ($transactionStatus == 'settlement') {
                $pembayaran->status_bayar = 'paid';
            } elseif ($transactionStatus == 'pending') {
                $pembayaran->status_bayar = 'pending';
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $pembayaran->status_bayar = 'failed';
            }

            $pembayaran->midtrans_transaction_id = $transactionId;
            $pembayaran->midtrans_payment_type = $paymentType;
            $pembayaran->midtrans_va_number = $vaNumbers[0]['va_number'] ?? null;
            $pembayaran->midtrans_qr_string = $qrString;
            $pembayaran->midtrans_raw_response = json_encode($notification);

            $pembayaran->save();

            DB::commit();

            return response()->json(['message' => 'OK'], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan webhook.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
