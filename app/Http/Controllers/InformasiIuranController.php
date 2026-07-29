<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InformasiIuran;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class InformasiIuranController extends Controller
{
    public function index(Request $request)
    {
        $query = InformasiIuran::query()->with([
            'penanggungJawab:nik,nama_warga',
        ]);

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;

            $query->where(function ($q) use ($keyword) {
                $q->where('judul_iuran', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama_warga_meninggal', 'LIKE', "%{$keyword}%")
                    ->orWhereHas('penanggungJawab', function ($sub) use ($keyword) {
                        $sub->where('nama_warga', 'LIKE', "%{$keyword}%");
                    });
            });
        }

        if ($request->filled('status_aktif')) {
            $query->where('status_aktif', $request->status_aktif);
        }

        if ($request->filled('jenis_iuran')) {
            $query->where('jenis_iuran', $request->jenis_iuran);
        }

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        $sortBy  = $request->query('sort_by');
        $sortDir = $request->query('sort_dir', 'asc');

        if ($sortBy) {
            $validColumns = Schema::getColumnListing('informasi_iuran');

            if (in_array($sortBy, $validColumns)) {
                $query->orderBy($sortBy, strtolower($sortDir) === 'desc' ? 'desc' : 'asc');
            } else {
                $query->orderByRaw('deleted_at IS NOT NULL ASC')->orderBy('created_at', 'desc');
            }
        } else {
            $query->orderByRaw('deleted_at IS NOT NULL ASC')->orderBy('created_at', 'desc');
        }

        $mode = $request->query('mode', 'client');

        if ($mode === 'admin') {
            $data = $query->paginate($request->get('per_page', 10));

            return ApiResponse::success($data, 'Data informasi iuran berhasil diambil.');
        }

        $data = $query->get();

        return ApiResponse::success($data, 'Data informasi iuran berhasil diambil.');
    }

    public function show($id)
    {
        $iuran = InformasiIuran::with('penanggungJawab:nik,nama_warga')->find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($iuran, 'Detail informasi iuran berhasil diambil.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'judul_iuran'          => 'required|string|max:150',
            'jenis_iuran'          => 'required|in:bulanan,kematian',
            'periode'              => 'nullable|integer|regex:/^[0-9]{4}$/|min:1900|max:2100',
            'jumlah_iuran'         => 'required|numeric|min:0',
            'keterangan'           => 'nullable|string',
            'nama_warga_meninggal' => 'nullable|string|max:150',
            'nik_penanggung_jawab' => 'nullable|string|exists:warga,nik',
        ], [
            'judul_iuran.required'     => 'Judul iuran wajib diisi.',
            'jenis_iuran.required'     => 'Jenis iuran wajib diisi.',
            'jenis_iuran.in'           => 'Jenis iuran hanya boleh berisi bulanan atau kematian.',
            'periode.regex'            => 'Periode harus berupa tahun 4 digit, misalnya 2025.',
            'periode.integer'          => 'Periode harus berupa angka.',
            'periode.min'              => 'Tahun periode minimal 1900.',
            'periode.max'              => 'Tahun periode maksimal 2100.',
            'jumlah_iuran.required'    => 'Jumlah iuran wajib diisi.',
            'jumlah_iuran.numeric'     => 'Jumlah iuran harus berupa angka.',
            'jumlah_iuran.min'         => 'Jumlah iuran minimal bernilai 0.',
            'nik_penanggung_jawab.exists' => 'NIK penanggung jawab tidak ditemukan.',
            'keterangan.string'        => 'Keterangan harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $data['status_aktif'] = true;

        if ($data['jenis_iuran'] === 'bulanan') {

            if (empty($data['periode'])) {
                return ApiResponse::error('Periode wajib diisi untuk iuran bulanan.', null, 422);
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

            $data['nama_warga_meninggal'] = null;
            $data['nik_penanggung_jawab'] = null;
        }

        if ($data['jenis_iuran'] === 'kematian') {

            $data['periode'] = null;

            if (empty($data['nama_warga_meninggal'])) {
                return ApiResponse::error('Nama warga yang meninggal wajib diisi untuk iuran kematian.', null, 422);
            }

            if (empty($data['nik_penanggung_jawab'])) {
                return ApiResponse::error('Keluarga penanggung jawab wajib diisi untuk iuran kematian.', null, 422);
            }
        }

        $iuran = InformasiIuran::create($data);

        app(\App\Services\IuranNotificationService::class)
            ->notifikasiIuranBaru($iuran);

        $this->writeLog(
            'create',
            'Menambahkan informasi iuran "' . $iuran->judul_iuran . '" dengan jenis "' . $iuran->jenis_iuran . '".',
            $request
        );

        return ApiResponse::success(null, 'Informasi iuran berhasil ditambahkan.', 201);
    }

    public function update(Request $request, $id)
    {
        $informasiIuran = InformasiIuran::find($id);

        if (!$informasiIuran) {
            return ApiResponse::error('Data informasi iuran tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'judul_iuran'          => 'required|string|max:150',
            'jenis_iuran'          => 'required|in:bulanan,kematian',
            'periode'              => 'nullable|integer|regex:/^[0-9]{4}$/|min:1900|max:2100',
            'jumlah_iuran'         => 'required|numeric|min:0',
            'keterangan'           => 'nullable|string',
            'nama_warga_meninggal' => 'nullable|string|max:150',
            'nik_penanggung_jawab' => 'nullable|string|exists:warga,nik',
        ], [
            'judul_iuran.required'     => 'Judul iuran wajib diisi.',
            'jenis_iuran.required'     => 'Jenis iuran wajib diisi.',
            'jenis_iuran.in'           => 'Jenis iuran hanya boleh berisi bulanan atau kematian.',
            'periode.regex'            => 'Periode harus berupa tahun 4 digit, misalnya 2025.',
            'periode.integer'          => 'Periode harus berupa angka.',
            'periode.min'              => 'Tahun periode minimal 1900.',
            'periode.max'              => 'Tahun periode maksimal 2100.',
            'jumlah_iuran.required'    => 'Jumlah iuran wajib diisi.',
            'jumlah_iuran.numeric'     => 'Jumlah iuran harus berupa angka.',
            'jumlah_iuran.min'         => 'Jumlah iuran minimal bernilai 0.',
            'nik_penanggung_jawab.exists' => 'NIK penanggung jawab tidak ditemukan.',
            'keterangan.string'        => 'Keterangan harus berupa teks.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        if ($data['jenis_iuran'] === 'bulanan') {

            if (empty($data['periode'])) {
                return ApiResponse::error('Periode wajib diisi untuk iuran bulanan.', null, 422);
            }

            $cek = InformasiIuran::where('jenis_iuran', 'bulanan')
                ->where('periode', $data['periode'])
                ->where('status_aktif', true)
                ->where('id', '!=', $informasiIuran->id)
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Iuran bulanan untuk tahun {$data['periode']} sudah ada dan masih aktif.",
                    null,
                    409
                );
            }

            $data['nama_warga_meninggal'] = null;
            $data['nik_penanggung_jawab'] = null;
        }

        if ($data['jenis_iuran'] === 'kematian') {

            $data['periode'] = null;

            if (empty($data['nama_warga_meninggal'])) {
                return ApiResponse::error('Nama warga yang meninggal wajib diisi untuk iuran kematian.', null, 422);
            }

            if (empty($data['nik_penanggung_jawab'])) {
                return ApiResponse::error('Keluarga penanggung jawab wajib diisi untuk iuran kematian.', null, 422);
            }
        }

        $informasiIuran->update($data);

        $this->writeLog(
            'update',
            'Memperbarui informasi iuran "' . $informasiIuran->judul_iuran . '".',
            $request
        );

        return ApiResponse::success(null, 'Informasi iuran berhasil diperbarui.');
    }

    public function destroy(Request $request, $id)
    {
        $iuran = InformasiIuran::withTrashed()->find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        if ($iuran->trashed()) {
            return ApiResponse::error('Data informasi iuran sudah dihapus sebelumnya.', null, 422);
        }

        $pembayaranExists = Pembayaran::where('id_informasi_iuran', $id)->exists();

        if ($pembayaranExists) {
            return ApiResponse::error('Data tidak bisa dihapus karena sudah digunakan pada pembayaran.', null, 422);
        }

        $iuran->status_aktif = false;
        $iuran->save();

        $iuran->delete();

        $this->writeLog(
            'delete',
            'Menghapus informasi iuran "' . $iuran->judul_iuran . '".',
            $request
        );

        return ApiResponse::success(null, 'Data informasi iuran berhasil dihapus.');
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status_aktif' => 'required|boolean',
        ], [
            'status_aktif.required' => 'Status aktif wajib diisi.',
            'status_aktif.boolean'  => 'Status aktif harus berupa pilihan Ya (true) atau Tidak (false).',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $informasiIuran = InformasiIuran::withTrashed()->find($id);

        if (!$informasiIuran) {
            return ApiResponse::error('Data informasi iuran tidak ditemukan.', null, 404);
        }

        $informasiIuran->status_aktif = $request->status_aktif;

        if (!$request->status_aktif) {
            $informasiIuran->delete();
        }

        if ($request->status_aktif) {
            $informasiIuran->restore();
        }

        $informasiIuran->save();

        $this->writeLog(
            'update',
            'Mengubah status informasi iuran "' . $informasiIuran->judul_iuran . '" menjadi ' . ($request->status_aktif ? 'aktif' : 'tidak aktif') . '.',
            $request
        );

        return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');
    }

    public function getActiveInformasiIuranForPayment(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $query = InformasiIuran::with(['penanggungJawab:nik,nama_warga'])
            ->where('status_aktif', 1);

        if ($request->filled('jenis_iuran')) {
            $query->where('jenis_iuran', $request->jenis_iuran);
        }

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;

            $query->where(function ($q) use ($keyword) {
                $q->where('judul_iuran', 'like', "%{$keyword}%")
                    ->orWhere('nama_warga_meninggal', 'like', "%{$keyword}%")
                    ->orWhere('periode', 'like', "%{$keyword}%")
                    ->orWhereHas('penanggungJawab', function ($sub) use ($keyword) {
                        $sub->where('nama_warga', 'like', "%{$keyword}%");
                    });
            });
        }

        $data = $query->orderBy('judul_iuran')->paginate($perPage);

        return ApiResponse::success($data, 'Data informasi iuran aktif berhasil diambil.');
    }

    private function writeLog(string $action, string $description, Request $request): void
    {
        try {
            $test = ActivityLog::create([
                'id_user'            => Auth::user()?->id,
                'nama_user_snapshot' => Auth::user()?->name,
                'action'             => $action,
                'description'        => $description,
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);
            
            Log::warning($test);
        } catch (\Throwable $e) {
            Log::warning("Gagal menulis activity log: {$e->getMessage()}");
        }
    }
}
