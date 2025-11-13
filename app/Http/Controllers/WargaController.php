<?php

namespace App\Http\Controllers;

use App\Models\Warga;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;

class WargaController extends Controller
{
    public function index()
    {
        $warga = Warga::all();

        return ApiResponse::success('Data warga berhasil diambil.', $warga);
    }

    public function show($id)
    {
        $warga = Warga::find($id);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        return ApiResponse::success('Detail warga berhasil diambil.', $warga);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|string|unique:warga,nik',
            'nama_warga' => 'required|string|max:100',
            'alamat' => 'required|string',
            'no_hp' => 'nullable|string|max:20',
            'regu_id' => 'nullable|exists:regu,id_regu',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors(), 422);
        }

        $warga = Warga::create($validator->validated());

        return ApiResponse::success('Data warga berhasil ditambahkan.', $warga, 201);
    }

    public function update(Request $request, $id)
    {
        $warga = Warga::find($id);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'nik' => 'sometimes|required|string|unique:warga,nik,' . $id . ',id_warga',
            'nama_warga' => 'sometimes|required|string|max:100',
            'alamat' => 'sometimes|required|string',
            'no_hp' => 'nullable|string|max:20',
            'regu_id' => 'nullable|exists:regu,id_regu',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors(), 422);
        }

        $warga->update($validator->validated());

        return ApiResponse::success('Data warga berhasil diperbarui.', $warga);
    }

    public function destroy($id)
    {
        $warga = Warga::find($id);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $warga->delete();

        return ApiResponse::success('Data warga berhasil dihapus.');
    }
}
