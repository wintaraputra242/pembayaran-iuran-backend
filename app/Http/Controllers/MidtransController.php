<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\InformasiIuran;
use App\Models\Pembayaran;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Midtrans\Config;
use Midtrans\CoreApi;

class MidtransController extends Controller
{
    public function createPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik'                => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'tanggal_bayar'      => 'required|date',
            'total_bayar'        => 'required|numeric|min:0',
            'bulan'              => 'nullable|array',
            'bulan.*'            => 'integer|min:1|max:12',
            'metode_bayar'       => 'required|string|in:qris,bank_transfer',
        ], [
            'nik.required'                => 'NIK warga wajib diisi.',
            'nik.exists'                  => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'tanggal_bayar.required'      => 'Tanggal pembayaran wajib diisi.',
            'tanggal_bayar.date'          => 'Format tanggal pembayaran tidak valid.',
            'total_bayar.required'        => 'Total nominal pembayaran wajib diisi.',
            'total_bayar.numeric'         => 'Total nominal pembayaran harus berupa angka.',
            'total_bayar.min'             => 'Total nominal pembayaran tidak boleh kurang dari 0.',
            'bulan.array'                 => 'Format data bulan harus berupa array (pilihan ganda).',
            'bulan.*.integer'             => 'Pilihan bulan harus berupa angka.',
            'bulan.*.min'                 => 'Pilihan bulan minimal adalah bulan ke-1 (Januari).',
            'bulan.*.max'                 => 'Pilihan bulan maksimal adalah bulan ke-12 (Desember).',
            'metode_bayar.required'       => 'Metode pembayaran wajib dipilih.',
            'metode_bayar.string'         => 'Metode pembayaran harus berupa teks.',
            'metode_bayar.in'             => 'Metode pembayaran yang dipilih harus QRIS atau Bank Transfer.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        if ($iuran->jenis_iuran === 'kematian') {
            $data['bulan'] = null;
        }

        $cek = Pembayaran::where('nik', $data['nik'])
            ->where('id_informasi_iuran', $data['id_informasi_iuran'])
            ->when($iuran->jenis_iuran === 'bulanan', function ($q) use ($data) {
                $q->where('bulan', json_encode($data['bulan']));
            })
            ->first();

        if ($cek) {
            return ApiResponse::error('Pembayaran untuk iuran ini sudah pernah dibuat sebelumnya.', null, 409);
        }

        $warga = Warga::where('nik', $data['nik'])->first();

        $pembayaran = Pembayaran::create([
            'nik'                    => $data['nik'],
            'id_informasi_iuran'     => $data['id_informasi_iuran'],
            'nik_snapshot'           => $warga->nik,
            'nama_warga_snapshot'    => $warga->nama_warga,
            'jumlah_iuran_snapshot'  => $iuran->jumlah_iuran ?? 0,
            'bulan'                  => $data['bulan'] ?? null,
            'tanggal_bayar'          => $data['tanggal_bayar'],
            'total_bayar'            => $data['total_bayar'],
            'metode_bayar'           => $data['metode_bayar'],
            'status_bayar'           => 'pending',
            'midtrans_order_id'      => null,
            'midtrans_transaction_id' => null,
            'midtrans_va_number'     => null,
            'midtrans_qr_string'     => null,
            'midtrans_payment_type'  => null,
            'midtrans_raw_response'  => null,
        ]);

        Config::$serverKey    = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized  = true;
        Config::$is3ds        = true;

        $orderId = 'PAY-' . $pembayaran->id . '-' . time();

        $payload = [
            "transaction_details" => [
                "order_id"     => $orderId,
                "gross_amount" => (int) $data['total_bayar'],
            ],
            "customer_details" => [
                "first_name" => $warga->nama_warga,
                "phone"      => $warga->no_hp,
            ],
        ];

        if ($data['metode_bayar'] === 'qris') {
            $payload["payment_type"] = "qris";
            $payload["qris"]         = ["acquirer" => "gopay"];
        }

        if ($data['metode_bayar'] === 'bank_transfer') {
            $payload["payment_type"]  = "bank_transfer";
            $payload["bank_transfer"] = ["bank" => "bca"];
        }

        try {
            $response = CoreApi::charge($payload);
        } catch (\Exception $e) {
            $pembayaran->delete();
            return ApiResponse::error('Gagal memproses pembayaran Midtrans: ' . $e->getMessage(), null, 500);
        }

        $pembayaran->update([
            'midtrans_order_id'       => $response->order_id ?? null,
            'midtrans_transaction_id' => $response->transaction_id ?? null,
            'midtrans_payment_type'   => $response->payment_type ?? null,
            'midtrans_raw_response'   => $response,
            'midtrans_va_number'      => $response->va_numbers[0]->va_number ?? null,
            'midtrans_qr_string'      => $response->actions[0]->url ?? null,
        ]);

        return ApiResponse::success([
            'pembayaran' => $pembayaran,
            'midtrans'   => $response,
        ], 'Transaksi Midtrans berhasil dibuat.', 201);
    }

    public function handleCallback(Request $request)
    {
        $orderId           = $request->order_id;
        $transactionStatus = $request->transaction_status;

        $pembayaran = Pembayaran::where('midtrans_order_id', $orderId)->first();

        if (!$pembayaran) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $status = match ($transactionStatus) {
            'settlement', 'capture' => 'paid',
            'pending'               => 'waiting_payment',
            'deny', 'cancel'        => 'failed',
            'expire'                => 'expired',
            default                 => 'pending'
        };

        $pembayaran->update([
            'status_bayar'         => $status,
            'midtrans_raw_response' => $request->all(),
        ]);

        return response()->json(['message' => 'Callback processed']);
    }

    public function checkStatus($orderId)
    {
        $isProduction = config('midtrans.is_production');
        $baseUrl      = $isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';

        $response = Http::withBasicAuth(config('midtrans.server_key'), '')
            ->get("{$baseUrl}/{$orderId}/status")
            ->json();

        return ApiResponse::success($response, 'Status transaksi berhasil diambil.');
    }

    public function cancelPayment($orderId)
    {
        $pembayaran = Pembayaran::where('midtrans_order_id', $orderId)->firstOrFail();

        $isProduction = config('midtrans.is_production');
        $baseUrl      = $isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';

        $response = Http::withBasicAuth(config('midtrans.server_key'), '')
            ->post("{$baseUrl}/{$orderId}/cancel")
            ->json();

        $pembayaran->update(['status_bayar' => 'failed']);

        return ApiResponse::success($response, 'Transaksi berhasil dibatalkan.');
    }
}
