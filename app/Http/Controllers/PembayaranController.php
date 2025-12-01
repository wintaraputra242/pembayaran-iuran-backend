<?php

namespace App\Http\Controllers;

use App\Models\Pembayaran;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;

class PembayaranController extends Controller
{
    public function index(Request $request)
    {
        $query = Pembayaran::with([
            'warga:nik,name',
            'informasiIuran:id,jenis_iuran,periode'
        ]);

        // Filter jika diperlukan
        if ($request->filled('nik')) {
            $query->where('nik', $request->nik);
        }

        if ($request->filled('status_bayar')) {
            $query->where('status_bayar', $request->status_bayar);
        }

        if ($request->filled('periode')) {
            $query->where('periode', $request->periode);
        }

        return ApiResponse::success(
            $query->get(),
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
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',

            'tanggal_bayar' => 'required|date',
            'total_bayar' => 'required|numeric|min:0',

            'bulan' => 'nullable|integer|min:1|max:12',
            'metode_bayar' => 'nullable|string',
            'bukti_pembayaran' => 'nullable|string',
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

            'bulan.integer' => 'Bulan harus berupa angka.',
            'bulan.min' => 'Bulan minimal 1.',
            'bulan.max' => 'Bulan maksimal 12.',

            'metode_bayar.string' => 'Metode bayar harus berupa teks.',
            'bukti_pembayaran.string' => 'Bukti pembayaran harus berupa path string.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        // ======================================================
        // 1. CEK INFORMASI IURAN
        // ======================================================
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        // Jika jenis bulanan → bulan wajib
        if ($iuran->jenis_iuran === 'bulanan' && !$data['bulan']) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        // Jika jenis kematian → bulan null
        if ($iuran->jenis_iuran === 'kematian') {
            $data['bulan'] = null;
        }

        // ======================================================
        // 2. CEK DUPLIKAT PEMBAYARAN
        // ======================================================
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

        // ======================================================
        // 3. SNAPSHOT WARGA
        // ======================================================
        $warga = Warga::where('nik', $data['nik'])->first();

        $data['nik_snapshot'] = $warga->nik;
        $data['nama_warga_snapshot'] = $warga->nama;

        // ======================================================
        // 4. SNAPSHOT NOMINAL IURAN
        // ======================================================
        $data['jumlah_iuran_snapshot'] = $iuran->jumlah_iuran ?? 0;

        // ======================================================
        // 5. STATUS PEMBAYARAN AWAL
        // ======================================================
        $data['status_bayar'] = 'pending';

        // ======================================================
        // 6. FIELD MIDTRANS — DISET NULL DULU
        // ======================================================
        $data['midtrans_order_id'] = null;
        $data['midtrans_transaction_id'] = null;
        $data['midtrans_va_number'] = null;
        $data['midtrans_qr_string'] = null;
        $data['midtrans_payment_type'] = null;
        $data['midtrans_raw_response'] = null;

        // ======================================================
        // 7. CREATE PEMBAYARAN
        // ======================================================
        $pembayaran = Pembayaran::create($data);

        return ApiResponse::success(
            $pembayaran,
            'Pembayaran berhasil dibuat.',
            201
        );

    }

    public function update(Request $request, $nik)
    {
        //
    }

    public function destroy($id)
    {
        $anggota = AnggotaRegu::find($id);

        if (!$anggota) {
            return ApiResponse::error('Data anggota regu tidak ditemukan.', null, 404);
        }

        $isLeader = $anggota->is_leader;

        $anggota->delete();

        $message = 'Anggota regu berhasil dihapus.';

        if ($isLeader) {
            $message .= ' Anggota yang dihapus merupakan ketua, silakan tunjuk ketua baru jika diperlukan.';
        }

        return ApiResponse::success(null, $message, 200);
    }


    public function updateStatusBayar(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status_bayar' => 'required|in:pending,waiting_payment,paid,failed,expired,canceled,manual'
        ], [
            'status_bayar.required' => 'Status pembayaran wajib diisi.',
            'status_bayar.in' => 'Status pembayaran tidak valid.'
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $pembayaran = Pembayaran::find($id);

        if (!$pembayaran) {
            return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
        }

        // Update status
        $pembayaran->status_bayar = $request->status_bayar;
        $pembayaran->save();

        return ApiResponse::success($pembayaran, 'Status pembayaran berhasil diperbarui.');
    }

    public function riwayat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|string|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists' => 'NIK tidak ditemukan pada data warga.'
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $nik = $request->nik;

        // 1. Ambil semua informasi iuran yang aktif
        $iuran = InformasiIuran::where('status_aktif', true)
            ->select('id', 'jenis_iuran', 'periode', 'jumlah_iuran')
            ->orderBy('jenis_iuran')
            ->orderBy('periode')
            ->get();

        // 2. Ambil daftar pembayaran warga
        $pembayaran = Pembayaran::where('nik', $nik)
            ->whereIn('status_bayar', ['paid', 'manual'])
            ->with('informasiIuran:id,jenis_iuran,periode,jumlah_iuran')
            ->get();

        // Mapping pembayaran berdasarkan id iuran
        $paidMap = $pembayaran->keyBy('id_informasi_iuran');

        // 3. Susun hasil
        $sudahDibayar = [];
        $belumDibayar = [];

        foreach ($iuran as $item) {

            if ($paidMap->has($item->id)) {
                // SUDAH DIBAYAR
                $sudahDibayar[] = [
                    'id_informasi_iuran' => $item->id,
                    'jenis_iuran' => $item->jenis_iuran,
                    'periode' => $item->periode,
                    'jumlah_iuran' => $item->jumlah_iuran,
                    'pembayaran' => $paidMap[$item->id],
                ];
            } else {
                // BELUM DIBAYAR
                $belumDibayar[] = [
                    'id_informasi_iuran' => $item->id,
                    'jenis_iuran' => $item->jenis_iuran,
                    'periode' => $item->periode,
                    'jumlah_iuran' => $item->jumlah_iuran,
                ];
            }
        }

        return ApiResponse::success([
            'total_iuran' => $iuran->count(),
            'sudah_dibayar' => $sudahDibayar,
            'belum_dibayar' => $belumDibayar
        ], 'Riwayat pembayaran berhasil diambil.');
    }

}
