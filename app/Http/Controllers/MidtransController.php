<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Pembayaran;
use App\Models\Warga;
use App\Models\InformasiIuran;
use Midtrans\Config;
use Midtrans\CoreApi;


class MidtransController extends Controller
{
    public function createPayment(Request $request)
    {
        // ============================
        // 0. VALIDASI REQUEST
        // ============================
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',

            'tanggal_bayar' => 'required|date',
            'total_bayar' => 'required|numeric|min:0',

            'bulan' => 'nullable|integer|min:1|max:12',
            'metode_bayar' => 'required|string|in:qris,bank_transfer',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        // ============================
        // 1. CEK INFORMASI IURAN
        // ============================
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        if ($iuran->jenis_iuran === 'bulanan' && !$data['bulan']) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        if ($iuran->jenis_iuran === 'kematian') {
            $data['bulan'] = null;
        }

        // ============================
        // 2. CEK DUPLIKAT PEMBAYARAN
        // ============================
        $cek = Pembayaran::where('nik', $data['nik'])
            ->where('id_informasi_iuran', $data['id_informasi_iuran'])
            ->when($iuran->jenis_iuran === 'bulanan', function ($q) use ($data) {
                $q->where('bulan', $data['bulan']);
            })
            ->first();

        if ($cek) {
            return ApiResponse::error(
                'Pembayaran untuk iuran ini sudah pernah dibuat sebelumnya.',
                null,
                409
            );
        }

        // ============================
        // 3. SNAPSHOT DATA WARGA
        // ============================
        $warga = Warga::where('nik', $data['nik'])->first();

        $data['nik_snapshot'] = $warga->nik;
        $data['nama_warga_snapshot'] = $warga->nama;
        $data['jumlah_iuran_snapshot'] = $iuran->jumlah_iuran ?? 0;

        // ============================
        // 4. DEFAULT STATUS
        // ============================
        $data['status_bayar'] = 'pending';

        // ============================
        // 5. FIELD MIDTRANS NULL DULU
        // ============================
        $data['midtrans_order_id'] = null;
        $data['midtrans_transaction_id'] = null;
        $data['midtrans_va_number'] = null;
        $data['midtrans_qr_string'] = null;
        $data['midtrans_payment_type'] = null;
        $data['midtrans_raw_response'] = null;

        // ============================
        // 6. SIMPAN PEMBAYARAN DULU
        // ============================
        $pembayaran = Pembayaran::create($data);

        // ============================
        // 7. KONFIGURASI MIDTRANS
        // ============================
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;

        // ============================
        // 8. BUAT ORDER ID
        // ============================
        $orderId = 'PAY-' . $pembayaran->id . '-' . time();

        // ============================
        // 9. REQUEST KE MIDTRANS
        // ============================

        $payload = [
            "transaction_details" => [
                "order_id" => $orderId,
                "gross_amount" => (int) $data['total_bayar'],
            ],
            "customer_details" => [
                "first_name" => $warga->nama,
                "email" => "user@example.com", // opsional
            ],
        ];

        if ($data['metode_bayar'] === 'qris') {
            $payload["payment_type"] = "qris";
            $payload["qris"] = [
                "acquirer" => "gopay"
            ];
        }

        if ($data['metode_bayar'] === 'bank_transfer') {
            $payload["payment_type"] = "bank_transfer";
            $payload["bank_transfer"] = [
                "bank" => "bca" // bisa diganti request input
            ];
        }

        try {
            $response = CoreApi::charge($payload);
        } catch (\Exception $e) {
            return ApiResponse::error("Gagal memproses pembayaran Midtrans: " . $e->getMessage(), null, 500);
        }

        // ============================
        // 10. SIMPAN RESPON MIDTRANS
        // ============================
        $pembayaran->update([
            'midtrans_order_id'        => $response->order_id ?? null,
            'midtrans_transaction_id'  => $response->transaction_id ?? null,
            'midtrans_payment_type'    => $response->payment_type ?? null,
            'midtrans_raw_response'    => json_encode($response),

            // VA NUMBER
            'midtrans_va_number' => $response->va_numbers[0]->va_number 
                ?? null,

            // QR STRING
            'midtrans_qr_string' => $response->actions[0]->url 
                ?? null,
        ]);

        return ApiResponse::success([
            'pembayaran' => $pembayaran,
            'midtrans' => $response,
        ], 'Transaksi Midtrans berhasil dibuat.', 201);
    }

    public function handleCallback(Request $request)
    {
        $orderId = $request->order_id;
        $transactionStatus = $request->transaction_status;

        $pembayaran = Pembayaran::where('midtrans_order_id', $orderId)->first();

        if (!$pembayaran) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        // Update status
        $status = match ($transactionStatus) {
            'settlement' => 'paid',
            'capture' => 'paid',
            'pending' => 'waiting_payment',
            'deny', 'cancel' => 'failed',
            'expire' => 'expired',
            default => 'pending'
        };

        $pembayaran->update([
            'status_bayar' => $status,
            'midtrans_raw_response' => json_encode($request->all())
        ]);

        return response()->json(['message' => 'Callback processed']);
    }

    public function checkStatus($orderId)
    {
        $url = "https://api.sandbox.midtrans.com/v2/{$orderId}/status";

        $response = Http::withBasicAuth(config('midtrans.server_key'), '')
            ->get($url)
            ->json();

        return ApiResponse::success($response, 'Status transaksi berhasil diambil.');
    }

    public function cancelPayment($orderId)
    {
        $url = "https://api.sandbox.midtrans.com/v2/{$orderId}/cancel";

        $response = Http::withBasicAuth(config('midtrans.server_key'), '')
            ->post($url)
            ->json();

        return ApiResponse::success($response, "Transaksi berhasil dibatalkan.");
    }

}
