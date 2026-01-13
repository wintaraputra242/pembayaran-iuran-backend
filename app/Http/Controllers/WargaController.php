<?php

namespace App\Http\Controllers;

use App\Models\Warga;
use App\Models\User;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel as FacadesExcel;

class WargaController extends Controller
{
    public function index(Request $request)
    {
        $query = Warga::query();

        // Global keyword search
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;

            $query->where(function ($q) use ($keyword) {
                $q->where('nik', 'LIKE', "%{$keyword}%")
                ->orWhere('nama_warga', 'LIKE', "%{$keyword}%")
                ->orWhere('no_hp', 'LIKE', "%{$keyword}%");
            });
        }

        if ($request->filled('status_keaktifan')) {
            $statusKeaktifan = $request->status_keaktifan;

            $query->where('status_keaktifan', '=', $statusKeaktifan);
        }

        // Pagination
        $limit = $request->get('limit', 10);

        $warga = $query
            ->orderBy('is_deleted', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return ApiResponse::success(
            $warga,
            'Data warga berhasil diambil.',
            200
        );
    }

    public function show($nik)
    {
        $warga = Warga::find($nik);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($warga, 'Detail warga berhasil diambil.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|string|unique:warga,nik',
            'nama_warga' => 'required|string|max:100',
            'alamat' => 'required|string',
            'no_hp' => 'nullable|string|max:20',
            // 'status_keaktifan' => 'nullable|in:aktif,tidak_aktif',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.string' => 'NIK harus berupa teks.',
            'nik.unique' => 'NIK sudah terdaftar.',

            'nama_warga.required' => 'Nama warga wajib diisi.',
            'nama_warga.string' => 'Nama warga harus berupa teks.',
            'nama_warga.max' => 'Nama warga maksimal 100 karakter.',

            'alamat.required' => 'Alamat wajib diisi.',
            'alamat.string' => 'Alamat harus berupa teks.',

            'no_hp.string' => 'Nomor HP harus berupa teks.',
            'no_hp.max' => 'Nomor HP maksimal 20 karakter.',

            // 'status_keaktifan.in' => 'Status keaktifan hanya boleh: aktif atau tidak_aktif.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        DB::beginTransaction();

        try {
            
            $newReqParams = [
                ...$validator->validated(),
                'id_user' => null,
                'status_keaktifan' => 'aktif',
                'id_deleted' => null,
                'deleted_at' => null,
            ];
    
            // Simpan data warga
            Warga::create($newReqParams);

            DB::commit();
    
            return ApiResponse::success(null, 'Data warga berhasil ditambahkan.', 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error(
                'Terjadi kesalahan.',
                $e->getMessage(),
                500
            );
        }

    }

    public function update(Request $request, $nik)
    {
        $warga = Warga::find($nik);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'nik' => 'sometimes|required|string|unique:warga,nik,' . $nik . ',nik',
            'nama_warga' => 'sometimes|required|string|max:100',
            'alamat' => 'sometimes|required|string',
            'no_hp' => 'nullable|string|max:20',
        ], [
            'nik.unique' => 'Nik sudah terdaftar.',
            'nik.required' => 'Nik wajib diisi.',

            'nama_warga.required' => 'Nama warga wajib diisi.',
            'alamat.required' => 'Alamat wajib diisi.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        // update data warga
        $warga->update($validator->validated());

        return ApiResponse::success(null, 'Data warga berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $warga = Warga::find($id);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        // Tandai tidak aktif, jangan hapus
        $warga->status_keaktifan = 'tidak_aktif';
        $warga->tanggal_nonaktif = now();
        $warga->is_deleted = true;
        $warga->deleted_at = now();
        $warga->save();

        return ApiResponse::success(null, 'Untuk sementara, data warga berhasil di nonaktifkan. Setelah 1 bulan berlalu, data warga baru benar-benar dihapus');
    }

    public function updateStatus(Request $request, $nik)
    {
        $validator = Validator::make($request->all(), [
            'status_keaktifan' => 'nullable|in:aktif,tidak_aktif',
        ], [
            'status_keaktifan.in' => 'Status keaktifan hanya boleh aktif atau tidak_aktif.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        $warga = Warga::find($nik);

        if (!$warga) {
            return response()->json([
                'message' => 'Warga tidak ditemukan'
            ], 404);
        }

        // Update status saja
        $warga->status_keaktifan = $request->status_keaktifan;

        // Jika status diubah menjadi tidak aktif → catat tanggal_nonaktif
        if ($request->status_keaktifan === 'tidak_aktif') {
            $warga->tanggal_nonaktif = now();
        }

        // Jika status diubah kembali menjadi aktif → reset tanggal_nonaktif
        if ($request->status_keaktifan === 'aktif') {
            $warga->tanggal_nonaktif = null;
            $warga->is_deleted = false;
            $warga->deleted_at = null;
        }

        $warga->save();

        return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        DB::beginTransaction();

        try {

            $rows = FacadesExcel::toArray([], $request->file('file'));

            // Ambil sheet pertama
            $data = $rows[0];

            // Asumsi baris pertama adalah header
            unset($data[0]);

            $inserted = 0;
            $skipped  = 0;

            foreach ($data as $row) {
                // mapping kolom sesuai urutan excel
                $nik        = trim($row[0] ?? '');
                $nama       = trim($row[1] ?? '');
                $alamat     = trim($row[2] ?? '');
                $hp         = trim($row[3] ?? '');

                if (!$nik || !$nama) {
                    $skipped++;
                    continue;
                }

                // Cegah duplikat NIK
                if (Warga::where('nik', $nik)->exists()) {
                    $skipped++;
                    continue;
                }

                Warga::create([
                    'nik'              => $nik,
                    'nama_warga'       => $nama,
                    'alamat'           => $alamat,
                    'no_hp'            => $hp,
                    'id_user'          => null,
                    'status_keaktifan' => 'aktif',
                ]);

                $inserted++;
            }

            DB::commit();

            return ApiResponse::success(null, 'Import data berhasil dilakukan');

        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error(
                'Terjadi kesalahan.',
                $e->getMessage(),
                500
            );
        }
    }

}
