<?php

namespace App\Http\Controllers;

use App\Models\Regu;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;

class ReguController extends Controller
{
    public function index(Request $request)
    {
        // Query dasar
        $query = Regu::query();

        // 🔍 Filter nama_regu (LIKE)
        if ($request->filled('nama_regu')) {
            $query->where('nama_regu', 'LIKE', '%' . $request->nama_regu . '%');
        }

        // 📌 Pagination:
        // page → nomor halaman
        // limit → jumlah data per halaman
        $limit = $request->get('limit', 10);

        // paginate otomatis membaca ?page=
        $regu = $query->paginate($limit);

        return ApiResponse::success(
            $regu,
            'Data regu berhasil diambil.',
            200
        );
    }


    public function show($nik)
    {
        $regu = Regu::find($nik);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($regu, 'Detail regu berhasil diambil.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_regu' => 'required|string|max:100',
        ], [
            'nama_regu.required' => 'Nama regu wajib diisi.',
            'nama_regu.string' => 'Nama regu harus berupa teks.',
            'nama_regu.max' => 'Nama regu maksimal 100 karakter.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        // Simpan data regu
        $regu = Regu::create($validator->validated());

        return ApiResponse::success($regu, 'Data regu berhasil ditambahkan.', 201);
    }

    public function update(Request $request, $nik)
    {
        $regu = Regu::find($nik);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_regu' => 'required|string|max:100',
        ], [
            'nama_regu.required' => 'Nama regu wajib diisi.',
            'nama_regu.string' => 'Nama regu harus berupa teks.',
            'nama_regu.max' => 'Nama regu maksimal 100 karakter.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        // update data regu
        $regu->update($validator->validated());

        return ApiResponse::success($regu, 'Data regu berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $regu = Regu::find($id);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        $regu->delete();

        return ApiResponse::success(null, 'Data regu berhasil dihapus.', 204);
    }
}
