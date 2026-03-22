<?php

namespace App\Http\Controllers;

use App\Models\InformasiIuran;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\ActivityLog;
use App\Models\Pembayaran;
use Illuminate\Support\Facades\Auth;

class InformasiIuranController extends Controller
{
    public function index(Request $request)
    {
        $query = InformasiIuran::withTrashed()->with([
            'warga:nik,nama_warga', 
        ]);

        /**
         * 🔍 FILTER KEYWORD
         * keyword akan mencari ke judul_iuran & jenis_iuran
         */
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;

            $query->where(function ($q) use ($keyword) {
                $q->where('judul_iuran', 'LIKE', "%{$keyword}%")
                ->orWhere('nama_warga_meninggal', 'LIKE', "%{$keyword}%")
                ->orWhereHas('warga', function ($sub) use ($keyword) {
                    $sub->where('nama_warga', 'LIKE', "%{$keyword}%");
                });
            });
        }

        /**
         * 🔘 FILTER STATUS
         */
        if ($request->filled('status_aktif')) {
            $query->where('status_aktif', $request->status_aktif);
        }

        if ($request->filled('jenis_iuran')) {
            $query->where('jenis_iuran', $request->jenis_iuran);
        }

        /**
         * ↕ SORTING
         */
        $sortBy  = $request->query('sort_by');
        $sortDir = $request->query('sort_dir', 'asc');

        if ($sortBy) {
            $validColumns = Schema::getColumnListing('informasi_iuran');

            if (in_array($sortBy, $validColumns)) {
                $query->orderBy(
                    $sortBy,
                    strtolower($sortDir) === 'desc' ? 'desc' : 'asc'
                );
            } else {
                $query->orderBy('created_at', 'desc');
            }
        } else {
            // default sorting
            $query->orderBy('is_deleted', 'asc');
            $query->orderBy('created_at', 'desc');
        }

        /**
         * MODE ADMIN / CLIENT
         */
        $mode = $request->query('mode', 'client');

        if ($mode === 'admin') {
            $data = $query->paginate(
                $request->get('per_page', 10)
            );

            return ApiResponse::success(
                $data,
                'Data informasi iuran berhasil diambil.'
            );
        }

        $data = $query->get();

        return ApiResponse::success(
            $data,
            'Data informasi iuran berhasil diambil.'
        );
    }


    public function show($id)
    {
        $iuran = InformasiIuran::find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($iuran, 'Detail informasi iuran berhasil diambil.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'judul_iuran' => 'required|string|max:150',
            'jenis_iuran' => 'required|in:bulanan,kematian',

            // hanya untuk bulanan
            'periode' => 'nullable|integer|regex:/^[0-9]{4}$/|min:1900|max:2100',

            'jumlah_iuran' => 'required|numeric|min:0',
            'keterangan' => 'nullable|string',

            // khusus iuran kematian
            'nama_warga_meninggal' => 'nullable|string|max:150',
            'nik_penanggung_jawab' => 'nullable|string|exists:warga,nik',
        ], [
            'judul_iuran.required' => 'Judul iuran wajib diisi.',

            'jenis_iuran.required' => 'Jenis iuran wajib diisi.',
            'jenis_iuran.in' => 'Jenis iuran hanya boleh berisi bulanan atau kematian.',

            'periode.regex' => 'Periode harus berupa tahun 4 digit, misalnya 2025.',
            'periode.integer' => 'Periode harus berupa angka.',
            'periode.min' => 'Tahun periode minimal 1900.',
            'periode.max' => 'Tahun periode maksimal 2100.',

            'jumlah_iuran.required' => 'Jumlah iuran wajib diisi.',
            'jumlah_iuran.numeric' => 'Jumlah iuran harus berupa angka.',
            'jumlah_iuran.min' => 'Jumlah iuran minimal bernilai 0.',

            'nik_penanggung_jawab.exists' => 'NIK penanggung jawab tidak ditemukan.',

            'keterangan.string' => 'Keterangan harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                $validator->errors()->first(),
                422
            );
        }

        $data = $validator->validated();

        $data['status_aktif'] = true;

        /**
         * ======================
         * LOGIC IURAN BULANAN
         * ======================
         */
        if ($data['jenis_iuran'] === 'bulanan') {

            if (empty($data['periode'])) {
                return ApiResponse::error(
                    'Periode wajib diisi untuk iuran bulanan.',
                    null,
                    422
                );
            }

            $cek = InformasiIuran::where('jenis_iuran', 'bulanan')
                ->where('periode', $data['periode'])
                ->where('status_aktif', true)
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Iuran bulanan untuk tahun {$data['periode']} sudah ada dan masih aktif.",
                    null,
                    409
                );
            }

            // pastikan field kematian kosong
            $data['nama_warga_meninggal'] = null;
            $data['nik_penanggung_jawab'] = null;
        }

        /**
         * ======================
         * LOGIC IURAN KEMATIAN
         * ======================
         */
        if ($data['jenis_iuran'] === 'kematian') {

            $data['periode'] = null;

            if (empty($data['nama_warga_meninggal'])) {
                return ApiResponse::error(
                    'Nama warga yang meninggal wajib diisi untuk iuran kematian.',
                    null,
                    422
                );
            }

            if (empty($data['nik_penanggung_jawab'])) {
                return ApiResponse::error(
                    'Keluarga penanggung jawab wajib diisi untuk iuran kematian.',
                    null,
                    422
                );
            }
        }

        InformasiIuran::create($data);

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'create',
            'description' => 'Menambahkan informasi iuran "' . $data['judul_iuran'] . '" dengan jenis "' . $data['jenis_iuran'] . '".',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Informasi iuran berhasil ditambahkan.',
            201
        );
    }

    public function update(Request $request, $id)
    {
        $informasiIuran = InformasiIuran::find($id);

        if (!$informasiIuran) {
            return ApiResponse::error(
                'Data informasi iuran tidak ditemukan.',
                null,
                404
            );
        }

        $validator = Validator::make($request->all(), [
            'judul_iuran' => 'required|string|max:150',
            'jenis_iuran' => 'required|in:bulanan,kematian',

            // hanya untuk bulanan
            'periode' => 'nullable|integer|regex:/^[0-9]{4}$/|min:1900|max:2100',

            'jumlah_iuran' => 'required|numeric|min:0',
            'keterangan' => 'nullable|string',

            // khusus iuran kematian
            'nama_warga_meninggal' => 'nullable|string|max:150',
            'nik_penanggung_jawab' => 'nullable|string|exists:warga,nik',
        ], [
            'judul_iuran.required' => 'Judul iuran wajib diisi.',

            'jenis_iuran.required' => 'Jenis iuran wajib diisi.',
            'jenis_iuran.in' => 'Jenis iuran hanya boleh berisi bulanan atau kematian.',

            'periode.regex' => 'Periode harus berupa tahun 4 digit, misalnya 2025.',
            'periode.integer' => 'Periode harus berupa angka.',
            'periode.min' => 'Tahun periode minimal 1900.',
            'periode.max' => 'Tahun periode maksimal 2100.',

            'jumlah_iuran.required' => 'Jumlah iuran wajib diisi.',
            'jumlah_iuran.numeric' => 'Jumlah iuran harus berupa angka.',
            'jumlah_iuran.min' => 'Jumlah iuran minimal bernilai 0.',

            'nik_penanggung_jawab.exists' => 'NIK penanggung jawab tidak ditemukan.',

            'keterangan.string' => 'Keterangan harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                $validator->errors()->first(),
                422
            );
        }

        $data = $validator->validated();

        /**
         * ======================
         * LOGIC IURAN BULANAN
         * ======================
         */
        if ($data['jenis_iuran'] === 'bulanan') {

            if (empty($data['periode'])) {
                return ApiResponse::error(
                    'Periode wajib diisi untuk iuran bulanan.',
                    null,
                    422
                );
            }

            $cek = InformasiIuran::where('jenis_iuran', 'bulanan')
                ->where('periode', $data['periode'])
                ->where('status_aktif', true)
                ->where('id', '!=', $informasiIuran->id) // ⬅️ PENTING
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Iuran bulanan untuk tahun {$data['periode']} sudah ada dan masih aktif.",
                    null,
                    409
                );
            }

            // pastikan field kematian kosong
            $data['nama_warga_meninggal'] = null;
            $data['nik_penanggung_jawab'] = null;
        }

        /**
         * ======================
         * LOGIC IURAN KEMATIAN
         * ======================
         */
        if ($data['jenis_iuran'] === 'kematian') {

            $data['periode'] = null;

            if (empty($data['nama_warga_meninggal'])) {
                return ApiResponse::error(
                    'Nama warga yang meninggal wajib diisi untuk iuran kematian.',
                    null,
                    422
                );
            }

            if (empty($data['nik_penanggung_jawab'])) {
                return ApiResponse::error(
                    'Keluarga penanggung jawab wajib diisi untuk iuran kematian.',
                    null,
                    422
                );
            }
        }

        $informasiIuran->update($data);

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'update',
            'description' => 'Memperbarui informasi iuran "' . $informasiIuran->judul_iuran . '".',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Informasi iuran berhasil diperbarui.',
            200
        );
    }

    public function destroy($id)
    {
        $iuran = InformasiIuran::find($id);

        $pembayaranExists = Pembayaran::where('id_informasi_iuran', $id)->exists();

        if ($pembayaranExists) {
            return ApiResponse::error('Data tidak bisa dihapus karena sudah digunakan pada pembayaran.', null, 422);
        }

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        $iuran->status_aktif = false;
        $iuran->tanggal_nonaktif = now();
        $iuran->is_deleted = true;
        $iuran->deleted_at = now();
        $iuran->save();

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'delete',
            'description' => 'Menonaktifkan informasi iuran "' . $iuran->judul_iuran . '".',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null, 
            'Untuk sementara, data informasi iuran berhasil di nonaktifkan. Setelah 1 bulan berlalu, data informasi iuran baru benar-benar dihapus.'
        );
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status_aktif' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        $informasiIuran = InformasiIuran::find($id);

        if (!$informasiIuran) {
            ApiResponse::error('Data informasi iuran tidak ditemukan.', null, 404);
        }

        // Update status saja
        $informasiIuran->status_aktif = $request->status_aktif;

        // Jika status diubah menjadi tidak aktif → catat tanggal_nonaktif
        if ($request->status_aktif === 0) {
            $informasiIuran->tanggal_nonaktif = now();
        }

        // Jika status diubah kembali menjadi aktif → reset tanggal_nonaktif
        if ($request->status_aktif === 1) {
            $informasiIuran->tanggal_nonaktif = null;
            $informasiIuran->is_deleted = false;
            $informasiIuran->deleted_at = null;
        }

        $informasiIuran->save();

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'update',
            'description' => 'Mengubah status informasi iuran "' . $informasiIuran->judul_iuran . '" menjadi ' . ($request->status_aktif ? 'aktif' : 'tidak aktif') . '.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');
    }

}
