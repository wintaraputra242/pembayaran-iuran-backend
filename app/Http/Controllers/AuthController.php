<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Warga;
use App\Helpers\ApiResponse;

class AuthController extends Controller
{
    /**
     * Login untuk admin, ketua_regu, dan warga
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string', // bisa NIK atau username
            'password' => 'required|string',
        ]);

        $user = null;

        // Cek apakah login menggunakan NIK (angka semua)
        if (is_numeric($request->username)) {
            // Login sebagai warga
            $warga = Warga::where('nik', $request->username)->first();

            if (!$warga) {
                return ApiResponse::error('NIK tidak ditemukan.', [
                    'username' => ['NIK tidak ditemukan.']
                ], 404);
            }

            // Pastikan warga punya akun di tabel user (relasi 1:1)
            $user = User::where('nik', $warga->nik)
                        ->where('role', 'warga')
                        ->first();
        } else {
            // Login sebagai admin atau ketua_regu
            $user = User::where('username', $request->username)->first();
        }

        // Jika user tidak ditemukan atau password salah
        if (!$user || !Hash::check($request->password, $user->password)) {
            return ApiResponse::error('Kredensial salah.', 'Username/NIK atau password salah.', 401);
        }

        // Hapus token lama (opsional, agar tidak menumpuk)
        $user->tokens()->delete();

        // Buat token baru
        $token = $user->createToken('auth_token')->plainTextToken;

        return ApiResponse::success([
            'user' => $user,
            'token' => $token,
        ], 'Login berhasil.', 200);
    }

    /**
     * Mengambil data user yang sedang login
     */
    public function user(Request $request)
    {
        if (!$request->user()) {
            return ApiResponse::error('User tidak terautentikasi.', null, 401);
        }

        return ApiResponse::success($request->user(), 'Data user berhasil diambil.', 200);
    }

    /**
     * Logout dan hapus token Sanctum
     */
    public function logout(Request $request)
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return ApiResponse::success(null, 'Logout berhasil.', 200);
    }
}
