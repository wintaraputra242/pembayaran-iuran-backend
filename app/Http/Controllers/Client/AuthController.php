<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{

    public function checkNik(Request $request)
    {
        $request->validate([
            'nik' => 'required|string',
        ]);

        $input = trim($request->nik);

        // Hanya izinkan format NIK (16 digit angka) atau nomor HP (08xxxxxxxxxx / 62xxxxxxxxxx)
        $isNik   = preg_match('/^\d{16}$/', $input);
        $isNoHp  = preg_match('/^(0|62)8[0-9]{8,12}$/', $input);

        if (!$isNik && !$isNoHp) {
            return ApiResponse::error('NIK atau nomor HP tidak ditemukan.', null, 422);
        }

        // Cari hanya di kalangan warga (bukan admin/staff)
        $user = User::where(function ($query) use ($input) {
            $query->where('username', $input)
                ->orWhere('no_hp', $input);
        })
            ->where('role', '!=', 'admin') // sesuaikan dengan struktur role di sistem Anda
            ->first();

        if (!$user) {
            return ApiResponse::error('NIK atau nomor HP tidak ditemukan.', null, 404);
        }

        return ApiResponse::success([
            'has_password' => !is_null($user->password),
        ], 'Berhasil.');
    }


    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik'      => 'required|string',
            'password' => 'required|string|min:6',
        ], [
            'nik.required'      => 'NIK / No. Handphone wajib diisi.',
            'password.required' => 'Password wajib diisi.',
            'password.min'      => 'Password minimal 6 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        // Cari by username (NIK) atau no_hp
        $user = User::where('username', $request->nik)
            ->orWhere('no_hp', $request->nik)
            ->first();

        if (!$user) {
            return ApiResponse::error('NIK atau nomor HP tidak ditemukan.', null, 404);
        }

        if (!$user->is_active) {
            return ApiResponse::error('Akun Anda telah dinonaktifkan. Hubungi administrator.', null, 403);
        }

        if (is_null($user->password)) {
            $user->password = Hash::make($request->password);
            $user->save();
        } else {
            if (!Hash::check($request->password, $user->password)) {
                return ApiResponse::error('Login gagal, password salah.', 'Password salah.', 401);
            }
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        if ($request->filled('fcm_token')) {
            UserDevice::updateOrCreate(
                [
                    'user_id'  => $user->id,
                    'app_type' => 'client',
                ],
                [
                    'fcm_token'    => $request->fcm_token,
                    'device_name'  => $request->header('User-Agent'),
                    'platform'     => $request->input('platform', 'web'),
                    'last_used_at' => now(),
                ]
            );
        }

        $this->writeLog($user->id, 'login', "Login berhasil: {$user->name} [{$user->role}]", $request);

        return ApiResponse::success([
            'user'         => $this->formatProfile($user->fresh()->load('warga')),
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ], 'Login berhasil.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->filled('fcm_token')) {
            UserDevice::where('user_id', $user->id)
                ->where('fcm_token', $request->fcm_token)
                ->delete();
        }

        $request->user()->currentAccessToken()->delete();

        $this->writeLog($user->id, 'logout', "Logout: {$user->username}", $request);

        return ApiResponse::success(null, 'Logout berhasil.');
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load('warga');

        return ApiResponse::success([
            'user' => $this->formatProfile($user),
        ], 'Data profil berhasil diambil.');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user  = $request->user();
        $warga = $user->warga;

        $validator = Validator::make($request->all(), [
            'nama_warga' => 'sometimes|required|string|max:100',
            'alamat'     => 'sometimes|required|string',
            'no_hp'      => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('warga', 'no_hp')->ignore($warga?->nik, 'nik'),
                Rule::unique('users', 'no_hp')->ignore($user->id),
            ],
            'password'   => 'nullable|string|min:6|confirmed',
        ], [
            'nama_warga.required'  => 'Nama wajib diisi.',
            'nama_warga.max'       => 'Nama maksimal 100 karakter.',
            'alamat.required'      => 'Alamat wajib diisi.',
            'no_hp.required'       => 'Nomor HP wajib diisi.',
            'no_hp.max'            => 'Nomor HP maksimal 20 karakter.',
            'no_hp.unique'         => 'Nomor HP sudah digunakan oleh warga lain.',
            'password.min'         => 'Password minimal 6 karakter.',
            'password.confirmed'   => 'Konfirmasi password tidak cocok.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            if ($warga) {
                $warga->update(array_filter([
                    'nama_warga' => $request->nama_warga,
                    'alamat'     => $request->alamat,
                    'no_hp'      => $request->no_hp,
                ], fn($v) => !is_null($v)));

                if ($request->filled('nama_warga')) {
                    $user->name = $request->nama_warga;
                }

                if ($request->filled('no_hp')) {
                    $user->no_hp = $request->no_hp;
                }
            }

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            $user->save();

            $this->writeLog($user->id, 'update', "Update profil: {$user->username}", $request);

            DB::commit();

            return ApiResponse::success([
                'user' => $this->formatProfile($user->fresh()->load('warga')),
            ], 'Profil berhasil diperbarui.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }


    private function formatProfile($user)
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'username' => $user->username,
            'role'     => $user->role,
            'warga'    => $user->warga ? [
                'nik'              => $user->warga->nik,
                'nama_warga'       => $user->warga->nama_warga,
                'alamat'           => $user->warga->alamat,
                'no_hp'            => $user->warga->no_hp,
                'status_keaktifan' => $user->warga->status_keaktifan,
                'regu'             => $user->warga->anggotaRegu()
                    ->whereNull('deleted_at')
                    ->where('status_keaktifan', 'aktif')
                    ->with('regu:id,nama_regu')
                    ->first()
                    ?->regu
                    ?->only(['id', 'nama_regu']),
            ] : null,
        ];
    }

    private function writeLog(?int $userId, string $action, string $description, Request $request): void
    {
        try {
            ActivityLog::create([
                'id_user'           => $userId,
                'nama_user_snapshot' => $userId
                    ? optional(Auth::user())->name
                    : null,
                'action'            => $action,
                'description'       => $description,
                'ip_address'        => $request->ip(),
                'user_agent'        => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Gagal menulis activity log: {$e->getMessage()}");
        }
    }
}
