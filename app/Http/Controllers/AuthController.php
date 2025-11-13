<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Helpers\ApiResponse;

class AuthController extends Controller
{
    /**
     * Tahap 1 - Cek NIK warga
     */
    public function checkNik(Request $request)
    {
        $request->validate([
            'nik' => 'required|string',
        ]);

        $warga = Warga::where('nik', $request->nik)->first();

        if (!$warga) {
            return ApiResponse::error('NIK tidak ditemukan.', null, 404);
        }

        // ini tidak diperlukan, karena nanti di disaat penambahan data warga, secara otomatis ditambahkan akun usernya juga 
        // DIGUNAKAN UNTUK TESTING
        $user = User::where('nik', $request->nik)->first();

        // Jika belum punya akun user → buat akun kosong
        if (!$user) {
            $user = User::create([
                'nik' => $request->nik,
                'role' => 'warga',
                'password' => null,
            ]);
        }

        // Jika password belum dibuat
        if (empty($user->password)) {
            return ApiResponse::success([
                'needs_password' => true,
            ], 'NIK valid. Silakan buat password Anda terlebih dahulu.');
        }

        // Jika password sudah ada, siap login
        return ApiResponse::success([
            'needs_password' => false,
        ], 'NIK valid. Silakan masukkan password Anda.');
    }

    /**
     * Tahap 2 - Buat password pertama kali
     */
    public function setPassword(Request $request)
{
    $request->validate([
        'nik' => 'required|string|exists:warga,nik',
        'password' => 'required|string|min:6|confirmed',
    ]);

    // Cek user berdasarkan NIK
    $user = User::where('nik', $request->nik)->first();

    if (!$user) {
        return ApiResponse::error('Akun tidak ditemukan.', null, 404);
    }

    // Jika password sudah ada, tidak perlu buat lagi
    if (!empty($user->password)) {
        return ApiResponse::error('Password sudah pernah dibuat. Silakan login langsung.', null, 400);
    }

    // Update password baru
    $user->update([
        'password' => Hash::make($request->password),
    ]);

    // Hapus token lama (jaga keamanan)
    $user->tokens()->delete();

    // Buat token baru (auto-login)
    $token = $user->createToken('auth_token')->plainTextToken;

    if ($user->role === 'warga' && $user->nik) {
        $user->load('warga');
    }

    return ApiResponse::success(
        [
            'user' => $user,
            'token' => $token,
        ],
        'Password berhasil dibuat dan login berhasil.'
    );
}


    /**
     * Tahap 3 - Login (Admin / Ketua Regu / Warga)
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string', // bisa username atau nik
            'password' => 'required|string',
        ]);

        // Login warga (jika input numeric)
        if (is_numeric($request->username)) {
            $user = User::where('nik', $request->username)->where('role', 'warga')->first();
        } else {
            $user = User::where('username', $request->username)->first();
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            return ApiResponse::error('Kredensial salah.', 'Username/NIK atau password salah.', 401);
        }

        $user->tokens()->delete(); // bersihkan token lama
        $token = $user->createToken('auth_token')->plainTextToken;

        if ($user->role === 'warga' && $user->nik) {
            $user->load('warga');
        }

        return ApiResponse::success([
            'user' => $user,
            'token' => $token,
        ], 'Login berhasil.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return ApiResponse::success(null, 'Logout berhasil.');
    }

    public function user(Request $request)
    {
        $user = $request->user();

        // Jika user adalah warga, ambil juga data dari tabel warga
        if ($user->role === 'warga' && $user->nik) {
            $user->load('warga');
        }

        return ApiResponse::success($user, 'Data user login.', 200);
    }
}
