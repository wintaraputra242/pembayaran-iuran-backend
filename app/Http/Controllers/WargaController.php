<?php

namespace App\Http\Controllers;

use App\Models\Warga;
use App\Models\User;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;

class WargaController extends Controller
{
    public function index(Request $request)
    {
        // Query dasar
        $query = Warga::query();

        // 🔍 Filter nama_warga (LIKE)
        if ($request->filled('nama_warga')) {
            $query->where('nama_warga', 'LIKE', '%' . $request->nama_warga . '%');
        }

        // 🔍 Filter status_keaktifan
        if ($request->filled('status_keaktifan')) {
            $query->where('status_keaktifan', $request->status_keaktifan);
        }

        // 📌 Pagination:
        // page → nomor halaman
        // limit → jumlah data per halaman
        $limit = $request->get('limit', 10);

        // paginate otomatis membaca ?page=
        $warga = $query->paginate($limit);

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
            'status_keaktifan' => 'nullable|in:aktif,tidak_aktif',
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

            'status_keaktifan.in' => 'Status keaktifan hanya boleh: aktif atau tidak_aktif.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        // Simpan data warga
        $warga = Warga::create($validator->validated());

        // 🔥 Saat warga ditambahkan → otomatis buat akun user
        $user = User::create([
            'nik'      => $warga->nik,
            'role'     => 'warga',
            'password' => null,      // password belum dibuat
            'name'     => $warga->nama_warga,
            'username' => null,
            'email'    => null
        ]);

        return ApiResponse::success($warga, 'Data warga berhasil ditambahkan.', 201);
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
            'status_keaktifan' => 'nullable|in:aktif,tidak_aktif',
        ], [
            'nik.unique' => 'Nik sudah terdaftar.',
            'nik.required' => 'Nik wajib diisi.',

            'nama_warga.required' => 'Nama warga wajib diisi.',
            'alamat.required' => 'Alamat wajib diisi.',

            'status_keaktifan.in' => 'Status keaktifan hanya boleh aktif atau tidak_aktif.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

         $nikLama = $warga->nik;

        // update data warga
        $warga->update($validator->validated());

        // jika nik berubah, update juga nik di users
        if ($request->has('nik') && $request->nik !== $nikLama) {

            User::where('nik', $nikLama)->update([
                'nik' => $request->nik
            ]);
        }

        return ApiResponse::success($warga, 'Data warga berhasil diperbarui.');
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
        $warga->save();

        return ApiResponse::success(null, 'Warga berhasil dinonaktifkan. Data akan dihapus permanen setelah 1 bulan.');
    }

    public function updateStatus(Request $request, $id)
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

        $warga = Warga::find($id);

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
        }

        $warga->save();

        return ApiResponse::success($warga, 'Status keaktifan berhasil diperbarui.');
    }
}
