<?php

namespace App\Http\Controllers;

use App\Models\Regu;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\AnggotaRegu;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;


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
        if ($request->filled('status_keaktifan')) {
            $query->where('status_keaktifan', '=', $request->status_keaktifan);
        }

        // Pagination
        $limit = $request->get('limit', 10);

        $regu = $query
            ->orderBy('is_deleted', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

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

            $user = User::create([
                'name' => $regu->nama_regu,
                'username' => $username,
                'password' => Hash::make($plainPassword),
                'role' => 'ketua_regu',
                'is_active' => true,
            ]);

            $regu->id_user = $user->id;
            $regu->save();

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

            ActivityLog::create([
                'id_user' => Auth::id(),
                'action' => 'create',
                'description' => 'Menambahkan regu baru dengan nama "' . $regu->nama_regu . '" beserta akun ketua regu.',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

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

    public function update(Request $request, $id)
    {
        $regu = Regu::find($id);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_regu' => 'required|string|max:100|unique:regu,nama_regu,' . $regu->id,
        ], [
            'nama_regu.required' => 'Nama regu wajib diisi.',
            'nama_regu.string'   => 'Nama regu harus berupa teks.',
            'nama_regu.max'      => 'Nama regu maksimal 100 karakter.',
            'nama_regu.unique'   => 'Nama regu sudah digunakan.',
        ]);

        if ($validator->fails()) {
            $firstError = collect($validator->errors()->all())->first();
            return ApiResponse::error('Validasi gagal.', $firstError, 422);
        }

        DB::beginTransaction();

        try {
            $oldNamaRegu = $regu->nama_regu;

            /** 1️⃣ UPDATE REGU */
            $regu->update([
                'nama_regu' => $request->nama_regu,
            ]);

            /** 2️⃣ UPDATE USER + PASSWORD */
            $user = User::where('role', 'ketua_regu')
                ->where('name', $oldNamaRegu)
                ->first();

            if ($user) {
                $username = Str::slug($request->nama_regu, '_');
                $plainPassword = $username . now()->format('d') . now()->format('s');

                $user->update([
                    'name'     => $request->nama_regu,
                    'username' => $username,
                    'password' => Hash::make($plainPassword),
                ]);

                /** 3️⃣ UPDATE FILE PASSWORD */
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
            }

            DB::commit();

            ActivityLog::create([
                'id_user' => Auth::id(),
                'action' => 'update',
                'description' => 'Memperbarui data regu dari "' . $oldNamaRegu . '" menjadi "' . $regu->nama_regu . '".',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return ApiResponse::success(
                $regu,
                'Data regu, akun, dan password berhasil diperbarui.'
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

    public function destroy($id)
    {
        $regu = Regu::find($id);

        if (!$regu) {
            return ApiResponse::error(
                'Data regu tidak ditemukan.',
                null,
                404
            );
        }

        DB::transaction(function () use ($regu) {

            AnggotaRegu::where('id_regu', $regu->id)
                ->whereNull('deleted_at')
                ->delete();

            $regu->status_keaktifan = 'tidak_aktif';
            $regu->tanggal_nonaktif = now();
            $regu->is_deleted = true;
            $regu->deleted_at = now();
            $regu->save();
        });

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'delete',
            'description' => 'Menonaktifkan regu "' . $regu->nama_regu . '" dan menghapus seluruh anggota dari regu tersebut.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Regu berhasil dinonaktifkan dan seluruh anggota regu telah di-reset. Setelah 1 bulan berlalu, data regu akan dihapus permanen.'
        );
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

        $regu = Regu::find($id);

        if (!$regu) {
            ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        // Update status saja
        $regu->status_keaktifan = $request->status_keaktifan;

        // Jika status diubah menjadi tidak aktif → catat tanggal_nonaktif
        if ($request->status_keaktifan === 'tidak_aktif') {
            $regu->tanggal_nonaktif = now();
        }

        // Jika status diubah kembali menjadi aktif → reset tanggal_nonaktif
        if ($request->status_keaktifan === 'aktif') {
            $regu->tanggal_nonaktif = null;
            $regu->is_deleted = false;
            $regu->deleted_at = null;
        }

        $regu->save();

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'update',
            'description' => 'Mengubah status regu "' . $regu->nama_regu . '" menjadi ' . $request->status_keaktifan . '.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');
    }
}
