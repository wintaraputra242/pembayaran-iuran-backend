<?php

namespace App\Http\Controllers;

use App\Models\Regu;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


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
            'nama_regu' => 'required|string|max:100|unique:regu,nama_regu',
        ], [
            'nama_regu.required' => 'Nama regu wajib diisi.',
            'nama_regu.string' => 'Nama regu harus berupa teks.',
            'nama_regu.max' => 'Nama regu maksimal 100 karakter.',
            'nama_regu.unique' => 'Nama regu sudah digunakan.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        DB::beginTransaction();

        try {
            /** 1️⃣ CREATE REGU */
            $regu = Regu::create([
                'nama_regu' => $request->nama_regu,
            ]);

            /** 2️⃣ GENERATE USER */
            $username = Str::slug($regu->nama_regu, '_');
            $plainPassword = $username . now()->format('d') . now()->format('s');

            User::create([
                'name' => $regu->nama_regu,
                'username' => $username,
                'password' => Hash::make($plainPassword),
                'role' => 'ketua_regu',
                'is_active' => true,
            ]);

            /** 3️⃣ SIMPAN PASSWORD KE FILE */
            $passwordPath = 'credentials/passwords.json';

            $passwords = [];

            if (Storage::exists($passwordPath)) {
                $passwords = json_decode(
                    Storage::get($passwordPath),
                    true
                );
            }

            $passwords['regu_' . $regu->id] = [
                'username' => $username,
                'password' => $plainPassword,
            ];

            Storage::put(
                $passwordPath,
                json_encode($passwords, JSON_PRETTY_PRINT)
            );

            DB::commit();

            return ApiResponse::success(
                null,
                'Regu beserta akunnya berhasil dibuat dan diperbarui.',
                201
            );

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
