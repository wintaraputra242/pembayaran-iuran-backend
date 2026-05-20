<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use App\Models\Regu;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReguController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Regu::with('ketuaRegu:id,name,username,is_active');

        if ($request->filled('nama_regu')) {
            $query->where('nama_regu', 'LIKE', '%' . $request->nama_regu . '%');
        }

        if ($request->filled('status_keaktifan')) {
            $query->where('status_keaktifan', $request->status_keaktifan);
        }

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        $limit = $request->get('limit', 10);

        $regu = $query
            ->orderByRaw('deleted_at IS NOT NULL ASC')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return ApiResponse::success($regu, 'Data regu berhasil diambil.');
    }

    public function show(int $id): JsonResponse
    {
        $regu = Regu::with([
                    'ketuaRegu:id,name,username,is_active',
                    'anggotaRegu.warga:nik,nama_warga,no_hp',
                ])
                ->withTrashed()
                ->find($id);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($regu, 'Detail regu berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nama_regu' => 'required|string|max:100|unique:regu,nama_regu',
        ], [
            'nama_regu.required' => 'Nama regu wajib diisi.',
            'nama_regu.string'   => 'Nama regu harus berupa teks.',
            'nama_regu.max'      => 'Nama regu maksimal 100 karakter.',
            'nama_regu.unique'   => 'Nama regu sudah digunakan.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $regu = Regu::create([
                'nama_regu'        => $request->nama_regu,
                'status_keaktifan' => 'aktif',
            ]);

            $username      = Str::slug($regu->nama_regu, '_');
            $plainPassword = $username . now()->format('d') . now()->format('s');

            $user = User::create([
                'name'      => $regu->nama_regu,
                'username'  => $username,
                'password'  => Hash::make($plainPassword),
                'role'      => 'ketua_regu',
                'is_active' => true,
            ]);

            $regu->id_user = $user->id;
            $regu->save();

            $this->saveCredential($regu->id, $username, $plainPassword);

            $this->writeLog(
                'create',
                "Membuat regu baru \"{$regu->nama_regu}\" beserta akun ketua regu (username: {$username}).",
                $request
            );

            DB::commit();

            return ApiResponse::success(null, 'Regu beserta akunnya berhasil dibuat.', 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $regu = Regu::find($id);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_regu' => [
                'required', 'string', 'max:100',
                Rule::unique('regu', 'nama_regu')->ignore($regu->id),
            ],
        ], [
            'nama_regu.required' => 'Nama regu wajib diisi.',
            'nama_regu.string'   => 'Nama regu harus berupa teks.',
            'nama_regu.max'      => 'Nama regu maksimal 100 karakter.',
            'nama_regu.unique'   => 'Nama regu sudah digunakan.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $oldNamaRegu = $regu->nama_regu;

            $regu->update(['nama_regu' => $request->nama_regu]);

            $user = $regu->id_user ? User::find($regu->id_user) : null;

            if ($user) {
                $username      = Str::slug($request->nama_regu, '_');
                $plainPassword = $username . now()->format('d') . now()->format('s');

                $user->update([
                    'name'     => $request->nama_regu,
                    'username' => $username,
                    'password' => Hash::make($plainPassword),
                ]);

                $user->tokens()->delete();

                $this->saveCredential($regu->id, $username, $plainPassword);
            }

            $this->writeLog(
                'update',
                "Memperbarui regu dari \"{$oldNamaRegu}\" menjadi \"{$regu->nama_regu}\".",
                $request
            );

            DB::commit();

            return ApiResponse::success(
                $regu->load('ketuaRegu:id,name,username'),
                'Data regu, akun, dan password berhasil diperbarui.'
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $regu = Regu::find($id);

        if (!$regu) {
            return ApiResponse::error('Data regu tidak ditemukan.', null, 404);
        }

        DB::beginTransaction();

        try {
            if ($regu->id_user) {
                $user = User::find($regu->id_user);
                if ($user) {
                    $user->tokens()->delete();
                    $user->update(['is_active' => false]);
                }
            }

            $regu->status_keaktifan = 'tidak_aktif';
            $regu->save();

            $regu->delete();

            $this->writeLog(
                'delete',
                "Soft delete regu \"{$regu->nama_regu}\" beserta seluruh anggotanya.",
                $request
            );

            DB::commit();

            return ApiResponse::success(null, 'Regu berhasil dinonaktifkan beserta seluruh anggotanya.');

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status_keaktifan' => ['required', Rule::in(['aktif', 'tidak_aktif'])],
        ], [
            'status_keaktifan.required' => 'Status keaktifan wajib diisi.',
            'status_keaktifan.in'       => 'Status keaktifan hanya boleh: aktif atau tidak_aktif.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $regu = Regu::withTrashed()->find($id);

        if (!$regu) {
        }

        $statusBaru = $request->status_keaktifan;

        DB::beginTransaction();

        try {
            if ($regu->trashed() && $statusBaru === 'aktif') {
                $regu->restore();

                if ($regu->id_user) {
                    User::where('id', $regu->id_user)
                        ->withTrashed()
                        ->update(['is_active' => true]);
                }
            }

            $regu->status_keaktifan = $statusBaru;
            $regu->save();

            if ($statusBaru === 'tidak_aktif' && $regu->id_user) {
                $user = User::find($regu->id_user);
                if ($user) {
                    $user->tokens()->delete();
                    $user->update(['is_active' => false]);
                }
            }

            $this->writeLog(
                'update_status',
                "Mengubah status regu \"{$regu->nama_regu}\" menjadi {$statusBaru}.",
                $request
            );

            DB::commit();

            return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }

    private function saveCredential(int $reguId, string $username, string $plainPassword): void
    {
        $passwordPath = 'credentials/passwords.json';

        $passwords = [];

        if (Storage::exists($passwordPath)) {
            $raw = Storage::get($passwordPath);
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $passwords = $decoded;
            }
        }

        $passwords['regu_' . $reguId] = [
            'username' => $username,
            'password' => $plainPassword,
        ];

        Storage::put($passwordPath, json_encode($passwords, JSON_PRETTY_PRINT));
    }

    private function writeLog(string $action, string $description, Request $request): void
    {
        try {
            ActivityLog::create([
                'id_user'            => Auth::id(),
                'nama_user_snapshot' => Auth::user()?->name,
                'action'             => $action,
                'description'        => $description,
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Gagal menulis activity log: {$e->getMessage()}");
        }
    }
}