<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'Username wajib diisi.',
            'username.string'   => 'Username harus berupa teks.',
            'password.required' => 'Password wajib diisi.',
            'password.string'   => 'Password harus berupa teks.',
        ]);

        if (!Auth::attempt($credentials)) {
            $this->writeLog(null, 'login_failed', "Login gagal untuk username: {$credentials['username']}", $request);

            return ApiResponse::error('Login gagal', 'Username atau password salah', 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        
        if ($user->trashed()) {
            Auth::logout();
            $this->writeLog(null, 'login_failed', "Login ditolak — akun terhapus: {$user->username}", $request);

            return ApiResponse::error('Login gagal', 'Akun tidak ditemukan', 401);
        }

        if (!$user->is_active) {
            Auth::logout();
            $this->writeLog($user->id, 'login_failed', "Login ditolak — akun nonaktif: {$user->username}", $request);

            return ApiResponse::error('Akses ditolak', 'Akun Anda telah dinonaktifkan. Hubungi administrator.', 403);
        }

        if (!in_array($user->role, ['admin', 'ketua_regu'])) {
            Auth::logout();
            $this->writeLog($user->id, 'login_failed', "Login ditolak — role tidak diizinkan: {$user->role}", $request);

            return ApiResponse::error('Akses ditolak', 'Role tidak diizinkan untuk mengakses aplikasi ini', 403);
        }

        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->writeLog($user->id, 'login_success', "Login berhasil: {$user->username} [{$user->role}]", $request);

        return ApiResponse::success([
            'user'         => $this->formatUser($user),
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ], 'Login berhasil');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->user()->currentAccessToken()->delete();

        $this->writeLog($user->id, 'logout', "Logout berhasil: {$user->username}", $request);

        return ApiResponse::success(null, 'Logout berhasil, token telah dihapus');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->delete();

        $this->writeLog($user->id, 'logout_all', "Logout dari semua device: {$user->username}", $request);

        return ApiResponse::success(null, 'Logout dari semua perangkat berhasil');
    }


    public function user(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->formatUser($request->user()),
            'Data user login'
        );
    }
 
    private function formatUser($user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'username' => $user->username,
            'role'     => $user->role,
            'is_active' => $user->is_active,
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
