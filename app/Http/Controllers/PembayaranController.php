<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use App\Models\InformasiIuran;
use App\Models\Notification as NotificationModel;
use App\Models\Pembayaran;
use App\Models\Warga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Midtrans\Notification;
use Midtrans\Snap;

class PembayaranController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Pembayaran::with([
            'warga.anggotaRegu.regu',
            'informasiIuran',
            'diprosesoleh:id,name,role',
        ]);

        if ($request->filled('nama_warga')) {
            $query->where(function ($q) use ($request) {
                $q->where('nama_warga_snapshot', 'like', '%' . $request->nama_warga . '%')
                    ->orWhereHas(
                        'warga',
                        fn($q2) =>
                        $q2->where('nama_warga', 'like', '%' . $request->nama_warga . '%')
                    );
            });
        }

        if ($request->filled('nik')) {
            $query->where('nik_snapshot', $request->nik);
        }

        if ($request->filled('regu')) {
            $query->whereHas(
                'warga.anggotaRegu.regu',
                fn($q) =>
                $q->where('id', $request->regu)
            );
        }

        if ($request->filled('jenis_iuran')) {
            $query->whereHas(
                'informasiIuran',
                fn($q) =>
                $q->where('jenis_iuran', $request->jenis_iuran)
            );
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
                $request->end_date,
            ]);
        }

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::success($data, 'Data pembayaran berhasil diambil.');
    }

    public function show(int $nik): JsonResponse
    {
        $pembayaran = Pembayaran::with([
            'warga',
            'informasiIuran',
            'diprosesoleh:id,name,role',
        ])->where('nik', $nik)->first();

        if (!$pembayaran) {
            return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($pembayaran, 'Detail pembayaran berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik'                => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'tanggal_bayar'      => 'required|date',
            'total_bayar'        => 'required|numeric|min:0',
            'bulan'              => 'nullable|array',
            'bulan.*'            => 'integer|min:1|max:12',
            'metode_bayar'       => 'required|in:tunai,transfer,qris',
            'bukti_pembayaran'   => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ], [
            'nik.required'                => 'NIK wajib diisi.',
            'nik.exists'                  => 'NIK tidak ditemukan dalam data warga.',
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran tidak valid.',
            'tanggal_bayar.required'      => 'Tanggal bayar wajib diisi.',
            'tanggal_bayar.date'          => 'Tanggal bayar harus berupa tanggal yang valid.',
            'total_bayar.required'        => 'Total bayar wajib diisi.',
            'total_bayar.numeric'         => 'Total bayar harus berupa angka.',
            'total_bayar.min'             => 'Total bayar minimal bernilai 0.',
            'bulan.array'                 => 'Bulan harus berupa array.',
            'bulan.*.integer'             => 'Nilai bulan harus berupa angka.',
            'bulan.*.min'                 => 'Bulan minimal 1.',
            'bulan.*.max'                 => 'Bulan maksimal 12.',
            'metode_bayar.required'       => 'Metode pembayaran wajib diisi.',
            'metode_bayar.in'             => 'Metode bayar harus salah satu dari: tunai, transfer, atau qris.',
            'bukti_pembayaran.image'      => 'Bukti pembayaran harus berupa gambar.',
            'bukti_pembayaran.mimes'      => 'Bukti pembayaran harus berformat jpg, jpeg, atau png.',
            'bukti_pembayaran.max'        => 'Ukuran bukti pembayaran maksimal 2MB.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        if ($iuran->jenis_iuran === 'kematian') {
            $data['bulan'] = null;
        }

        $existingQuery = Pembayaran::where('nik', $data['nik'])
            ->where('id_informasi_iuran', $data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && !empty($data['bulan'])) {
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

        $warga = Warga::find($data['nik']);

        DB::beginTransaction();

        try {
            $statusBayar = 'pending';
            $pathBukti   = null;

            if ($data['metode_bayar'] === 'tunai') {
                if ($request->hasFile('bukti_pembayaran')) {
                    $file     = $request->file('bukti_pembayaran');
                    $filename = 'bukti-' . time() . '-' . Str::random(6) . '.jpg';

                    $manager = new ImageManager(new Driver());
                    $image   = $manager->read($file)->toJpeg(75);

                    Storage::disk('public')->put('bukti-pembayaran/' . $filename, (string) $image);

                    $pathBukti = 'bukti-pembayaran/' . $filename;
                }

                $statusBayar = 'manual';
            }

            do {
                $transactionId = 'TRX-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
            } while (Pembayaran::where('transaction_id', $transactionId)->exists());

            $orderId = 'IURAN-' . Str::uuid();

            $pembayaran = Pembayaran::create([
                'transaction_id'        => $transactionId,
                'nik'                   => $data['nik'],
                'id_informasi_iuran'    => $data['id_informasi_iuran'],
                'nik_snapshot'          => $warga->nik,
                'nama_warga_snapshot'   => $warga->nama_warga,
                'jumlah_iuran_snapshot' => $iuran->jumlah_iuran ?? 0,
                'bulan'                 => $data['bulan'] ?? null,
                'tanggal_bayar'         => $data['tanggal_bayar'],
                'total_bayar'           => $data['total_bayar'],
                'metode_bayar'          => $data['metode_bayar'],
                'status_bayar'          => $statusBayar,
                'processed_by'          => Auth::user()->id,
                'midtrans_order_id'     => $data['metode_bayar'] !== 'tunai' ? $orderId : null,
                'bukti_pembayaran'      => $pathBukti,
            ]);

            $snapToken = null;

            if (in_array($data['metode_bayar'], ['transfer', 'qris'])) {
                $params = [
                    'transaction_details' => [
                        'order_id'     => $orderId,
                        'gross_amount' => (int) $data['total_bayar'],
                    ],
                    'customer_details' => [
                        'first_name' => $warga->nama_warga,
                    ],
                    'expiry' => [
                        'unit'     => 'minutes',
                        'duration' => 30,
                    ],
                ];

                if ($data['metode_bayar'] === 'qris') {
                    $params['enabled_payments'] = ['other_qris'];
                }

                if ($data['metode_bayar'] === 'transfer') {
                    $params['enabled_payments'] = ['bank_transfer'];
                }

                $snapToken = Snap::getSnapToken($params);

                $pembayaran->update([
                    'status_bayar'         => 'waiting_payment',
                    'midtrans_raw_response' => json_encode(['snap_token' => $snapToken]),
                ]);
            }

            $this->writeLog(
                'create',
                "Membuat transaksi pembayaran ID {$pembayaran->id} untuk warga {$warga->nama_warga} (NIK: {$warga->nik}).",
                $request
            );

            DB::commit();

            return ApiResponse::success([
                'pembayaran' => $pembayaran,
                'snap_token' => $snapToken,
            ], 'Transaksi pembayaran berhasil.', 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat menyimpan pembayaran.', $e->getMessage(), 500);
        }
    }

    public function wargaUnpaidPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'bulan'              => 'nullable|integer|min:1|max:12',
            'nama_warga'         => 'nullable|string',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'bulan.integer'               => 'Bulan harus berupa angka.',
            'bulan.min'                   => 'Pilihan bulan minimal adalah bulan ke-1 (Januari).',
            'bulan.max'                   => 'Pilihan bulan maksimal adalah bulan ke-12 (Desember).',
            'nama_warga.string'           => 'Nama warga harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data  = $validator->validated();
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if (!$iuran) {
            return ApiResponse::error('Iuran tidak ditemukan.', null, 404);
        }

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        $user  = Auth::user();

        $query = Warga::with(['anggotaRegu.regu'])
            ->whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
                $q->where('id_informasi_iuran', $data['id_informasi_iuran'])
                    ->whereIn('status_bayar', ['paid', 'manual']);

                if ($iuran->jenis_iuran === 'bulanan') {
                    $q->whereJsonContains('bulan', (int) $data['bulan']);
                }
            });

        if ($user->role === 'ketua_regu') {
            $query->whereHas(
                'anggotaRegu.regu',
                fn($q) =>
                $q->where('id_user', $user->id)
            );
        }

        if ($request->filled('nama_warga')) {
            $query->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
        }

        $perPage = $request->get('per_page', 10);

        $warga = $query->orderBy('nama_warga')->paginate($perPage);

        return ApiResponse::success($warga, 'Daftar warga yang belum melakukan pembayaran berhasil diambil.');
    }

    public function historyAlreadyPaid(Request $request): JsonResponse
    {
        $request->validate([
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        $data = Pembayaran::with(['informasiIuran', 'diprosesoleh:id,name,role'])
            ->where('nik', $request->nik)
            ->whereIn('status_bayar', ['paid', 'manual'])
            ->orderByDesc('tanggal_bayar')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($data, 'Data riwayat pembayaran berhasil diambil.');
    }

    public function historyNotYetPaid(Request $request): JsonResponse
    {
        $request->validate([
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        $nik = $request->nik;

        $data = InformasiIuran::whereDoesntHave('pembayaran', function ($q) use ($nik) {
            $q->where('nik', $nik)
                ->whereIn('status_bayar', ['paid', 'manual']);
        })
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($data, 'Data iuran yang belum dibayar berhasil diambil.');
    }

    public function getPaidMonth(Request $request): JsonResponse
    {
        $request->validate([
            'id_informasi_iuran' => 'required|integer|exists:informasi_iuran,id',
            'nik'                => 'required|string|exists:warga,nik',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.integer'  => 'ID informasi iuran harus berupa angka.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'nik.required'                => 'NIK wajib diisi.',
            'nik.string'                  => 'NIK harus berupa teks.',
            'nik.exists'                  => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        $paidMonths = Pembayaran::where('id_informasi_iuran', $request->id_informasi_iuran)
            ->where('nik', $request->nik)
            ->whereIn('status_bayar', ['paid', 'manual'])
            ->pluck('bulan')
            ->flatten()
            ->unique()
            ->sort()
            ->values();

        return ApiResponse::success($paidMonths, 'Data bulan yang sudah dibayar berhasil diambil.');
    }

    public function getUnpaidWargaByLeader(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->role !== 'ketua_regu') {
            return ApiResponse::error('Akses ditolak.', null, 403);
        }

        $query = Warga::with(['anggotaRegu.regu'])
            ->whereHas(
                'anggotaRegu.regu',
                fn($q) =>
                $q->where('id_user', $user->id)
            )
            ->whereHas(
                'pembayaran',
                function ($q) {
                    $q->whereIn('status_bayar', ['pending', 'waiting_payment', 'failed', 'expired', 'canceled']);
                },
            )
            ->orWhere(function ($q) use ($user) {
                $q->whereHas(
                    'anggotaRegu.regu',
                    fn($q2) =>
                    $q2->where('id_user', $user->id)
                )
                    ->whereDoesntHave(
                        'pembayaran',
                        fn($q2) =>
                        $q2->where('status_bayar', 'paid')
                            ->whereHas(
                                'informasiIuran',
                                fn($q3) =>
                                $q3->where('status_aktif', true)->whereNull('deleted_at')
                            )
                    );
            });

        if ($request->filled('nama_warga')) {
            $query->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
        }

        $warga = $query
            ->orderBy('nama_warga')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($warga, 'Daftar warga yang masih memiliki iuran belum dibayar berhasil diambil.');
    }

    public function sendUnpaidResidentsNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'month'              => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data  = $validator->validated();
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['month'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        $wargaList = Warga::with(['user.devices'])
            ->whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
                $q->where('id_informasi_iuran', $data['id_informasi_iuran'])
                    ->where('status_bayar', 'paid');

                if ($iuran->jenis_iuran === 'bulanan') {
                    $q->whereJsonContains('bulan', (int) $data['month']);
                }
            })
            ->get();

        $tokens = $wargaList
            ->flatMap(fn($warga) => $warga->user?->devices?->pluck('fcm_token') ?? [])
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // if (empty($tokens)) {
        //     return ApiResponse::error(null, 'Tidak ada perangkat aktif yang terdaftar.');
        // }

        $firebase = new \App\Services\FirebaseService();
        $title    = 'Pengingat Pembayaran Iuran';
        $message  = "Anda belum melakukan pembayaran untuk iuran {$iuran->judul_iuran}.";

        $firebase->sendBulk($tokens, $title, $message);

        foreach ($wargaList as $warga) {
            if (!$warga->user) {
                continue;
            }

            NotificationModel::create([
                'title'   => $title,
                'message' => $message,
                'type'    => 'pengingat',
                'user_id' => $warga->user->id,
                'data'    => ['id_informasi_iuran' => $iuran->id],
            ]);
        }

        // $this->writeLog(
        //     'send_notification',
        //     "Mengirim notifikasi pengingat iuran \"{$iuran->judul_iuran}\" ke {$wargaList->count()} warga.",
        //     $request
        // );

        return ApiResponse::success(null, 'Notifikasi berhasil dikirim ke warga yang belum bayar.');
    }

    public function sendResidentNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik'                => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'month'              => 'nullable|integer|min:1|max:12',
        ], [
            'nik.required'                => 'NIK wajib diisi.',
            'nik.exists'                  => 'NIK yang dipilih tidak valid atau tidak ditemukan.',
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'month.integer'               => 'Bulan harus berupa angka.',
            'month.min'                   => 'Pilihan bulan minimal adalah bulan ke-1 (Januari).',
            'month.max'                   => 'Pilihan bulan maksimal adalah bulan ke-12 (Desember).',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $warga  = Warga::with('user.devices')->find($data['nik']);
        $tokens = $warga->user?->devices?->pluck('fcm_token')
            ->filter()->unique()->values()->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        if ($iuran->jenis_iuran === 'bulanan') {
            $bulanLabel = isset($data['month']) ? $monthNames[$data['month']] : 'bulan ini';

            $title   = 'Pengingat Iuran Bulanan';
            $message = "Yth. {$warga->nama_warga}, Anda belum melakukan pembayaran iuran bulanan untuk bulan {$bulanLabel}. Segera lakukan pembayaran. Terima kasih.";
        } else {
            $title   = 'Pengingat Iuran Kematian';
            $message = "Yth. {$warga->nama_warga}, Anda belum melakukan pembayaran iuran kematian untuk {$iuran->judul_iuran}. Segera lakukan pembayaran. Terima kasih.";
        }

        try {
            (new \App\Services\FirebaseService())->sendBulk($tokens, $title, $message);
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim FCM: ' . $e->getMessage());
        }

        NotificationModel::create([
            'title'   => $title,
            'message' => $message,
            'type'    => 'pengingat',
            'user_id' => $warga->user->id,
        ]);

        // $this->writeLog(
        //     'send_notification',
        //     "Mengirim notifikasi manual ke warga {$warga->nama_warga} (NIK: {$warga->nik}).",
        //     $request
        // );

        return ApiResponse::success(null, 'Notifikasi berhasil dikirim ke warga.');
    }

    public function sendAllUnpaidToResident(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::with('user.devices')->find($request->nik);

        $paidIuranIds = Pembayaran::where('nik', $warga->nik)
            ->where('status_bayar', 'paid')
            ->pluck('id_informasi_iuran');

        $unpaidList = InformasiIuran::whereNotIn('id', $paidIuranIds)
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->get();

        if ($unpaidList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada iuran yang belum dibayar.');
        }

        $tokens = $warga->user?->devices?->pluck('fcm_token')
            ->filter()->unique()->values()->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        $iuranList = $unpaidList->map(fn($i) => "- {$i->judul_iuran}")->implode("\n");
        $message   = "Anda memiliki {$unpaidList->count()} iuran yang belum dibayar:\n\n{$iuranList}\n\nSegera lakukan pembayaran.";
        $title     = 'Pengingat Iuran Belum Dibayar';

        (new \App\Services\FirebaseService())->sendBulk($tokens, $title, $message);

        NotificationModel::create([
            'title'   => $title,
            'message' => $message,
            'type'    => 'pengingat',
            'user_id' => $warga->user->id,
        ]);

        // $this->writeLog(
        //     'send_notification',
        //     "Mengirim ringkasan {$unpaidList->count()} tunggakan iuran ke warga {$warga->nama_warga}.",
        //     $request
        // );

        return ApiResponse::success(null, 'Notifikasi ringkasan berhasil dikirim.');
    }

    public function sendUnpaidOneByOneToResident(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::with('user.devices')->find($request->nik);

        $paidIuranIds = Pembayaran::where('nik', $warga->nik)
            ->where('status_bayar', 'paid')
            ->pluck('id_informasi_iuran');

        $unpaidList = InformasiIuran::whereNotIn('id', $paidIuranIds)
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->get();

        if ($unpaidList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada iuran yang belum dibayar.');
        }

        $tokens = $warga->user?->devices?->pluck('fcm_token')
            ->filter()->unique()->values()->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        $firebase = new \App\Services\FirebaseService();

        foreach ($unpaidList as $item) {
            $title   = 'Pengingat Iuran';
            $message = "Iuran {$item->judul_iuran} belum dibayar. Segera lakukan pembayaran.";

            $firebase->sendBulk($tokens, $title, $message);

            NotificationModel::create([
                'title'   => $title,
                'message' => $message,
                'type'    => 'pengingat',
                'user_id' => $warga->user->id,
                'data'    => ['id_informasi_iuran' => $item->id],
            ]);
        }

        // $this->writeLog(
        //     'send_notification',
        //     "Mengirim {$unpaidList->count()} notifikasi tunggakan iuran satu per satu ke warga {$warga->nama_warga}.",
        //     $request
        // );

        return ApiResponse::success(null, 'Notifikasi iuran dikirim satu per satu.');
    }

    public function handleNotification(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            DB::beginTransaction();

            $notification = new Notification();

            $transactionStatus = $notification->transaction_status;
            $paymentType       = $notification->payment_type;
            $orderId           = $notification->order_id;
            $transactionId     = $notification->transaction_id;
            $vaNumbers         = $notification->va_numbers ?? null;
            $qrString          = $notification->qr_string ?? null;
            $fraudStatus       = $notification->fraud_status ?? null;

            $pembayaran = Pembayaran::where('midtrans_order_id', $orderId)->first();

            if (!$pembayaran) {
                return response()->json(['message' => 'Order tidak ditemukan.'], 404);
            }

            $pembayaran->status_bayar = match (true) {
                $transactionStatus === 'capture' && $fraudStatus === 'accept'    => 'paid',
                $transactionStatus === 'capture' && $fraudStatus === 'challenge' => 'waiting_payment',
                $transactionStatus === 'settlement'                              => 'paid',
                $transactionStatus === 'pending'                                 => 'waiting_payment',
                $transactionStatus === 'deny'                                    => 'failed',
                $transactionStatus === 'expire'                                  => 'expired',
                $transactionStatus === 'cancel'                                  => 'canceled',
                default                                                          => $pembayaran->status_bayar,
            };

            $pembayaran->midtrans_transaction_id = $transactionId;
            $pembayaran->midtrans_payment_type   = $paymentType;
            $pembayaran->midtrans_va_number      = $vaNumbers[0]['va_number'] ?? null;
            $pembayaran->midtrans_qr_string      = $qrString;
            $pembayaran->midtrans_raw_response   = json_encode($notification);
            $pembayaran->save();

            ActivityLog::create([
                'id_user'            => null,
                'nama_user_snapshot' => 'midtrans-webhook',
                'action'             => 'webhook_midtrans',
                'description'        => "Webhook Midtrans: order {$orderId} → status {$pembayaran->status_bayar}.",
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);

            DB::commit();

            return response()->json(['message' => 'OK'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan webhook.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getPembayaranByRegu(Request $request)
    {
        $request->validate([
            'id_informasi_iuran' => 'nullable|exists:informasi_iuran,id',
            'id_regu'            => 'nullable|exists:regu,id',
            'status_bayar'       => 'nullable|in:paid,pending,failed',
            'nama_warga'         => 'nullable|string', // ← tambah ini
        ]);

        $user     = Auth::user();
        $perPage  = $request->input('per_page', 10);
        $reguId   = null;

        if ($user->role === 'ketua_regu') {
            $reguId = $user->regu()->whereNull('deleted_at')->value('id');

            if (!$reguId) {
                return ApiResponse::success([], 'Data pembayaran berhasil diambil.');
            }
        } elseif ($user->role === 'admin') {
            $reguId = $request->filled('id_regu') ? $request->id_regu : null;
        }

        $query = Pembayaran::with([
            'warga.anggotaRegu.regu',
            'informasiIuran',
            'diprosesoleh',
        ])
            ->whereNull('deleted_at')
            ->when($reguId, function ($q) use ($reguId) {
                $q->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                    $q->where('id_regu', $reguId)
                        ->whereNull('deleted_at')
                        ->where('status_keaktifan', 'aktif');
                });
            })
            ->when($request->filled('id_informasi_iuran'), function ($q) use ($request) {
                $q->where('id_informasi_iuran', $request->id_informasi_iuran);
            })
            ->when($request->filled('status_bayar'), function ($q) use ($request) {
                $q->where('status_bayar', $request->status_bayar);
            })
            ->when($request->filled('nama_warga'), function ($q) use ($request) {
                $q->whereHas('warga', function ($q) use ($request) {
                    $q->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
                });
            })
            ->orderByDesc('tanggal_bayar');

        $data = $query->paginate($perPage)->through(function ($item) {
            $anggotaAktif = $item->warga?->anggotaRegu
                ->whereNull('deleted_at')
                ->where('status_keaktifan', 'aktif')
                ->first();

            return [
                'id'                     => $item->id,
                'transaction_id'         => $item->transaction_id,
                'nik'                    => $item->nik,
                'nama_warga'             => $item->nama_warga_snapshot ?? $item->warga?->nama_warga,
                'regu'                   => $anggotaAktif?->regu?->nama_regu,
                'regu_id'                => $anggotaAktif?->regu?->id,
                'informasi_iuran'        => $item->informasiIuran ? [
                    'id'          => $item->informasiIuran->id,
                    'nama'        => $item->informasiIuran->judul_iuran,
                    'jenis_iuran' => $item->informasiIuran->jenis_iuran,
                ] : null,
                'bulan'                  => $item->bulan,
                'jumlah_iuran_snapshot'  => $item->jumlah_iuran_snapshot,
                'total_bayar'            => $item->total_bayar,
                'tanggal_bayar'          => $item->tanggal_bayar?->format('Y-m-d'),
                'metode_bayar'           => $item->metode_bayar,
                'status_bayar'           => $item->status_bayar,
                'processed_by'           => $item->diprosesoleh?->name,
                'bukti_pembayaran'       => $item->bukti_pembayaran,
            ];
        });

        return ApiResponse::success($data, 'Data pembayaran berhasil diambil.');
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
