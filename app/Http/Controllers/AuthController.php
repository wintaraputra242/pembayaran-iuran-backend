<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{

    /**
     * Tahap 3 - Login (Admin / Ketua Regu / Warga) (ini untuk login warga dan admin beserta ketua regu)
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return ApiResponse::error('Login gagal', 'Email atau password salah', 401);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        if (in_array($user->role, ['admin', 'ketua_regu'])) {
            return ApiResponse::success($user, 'Login berhasil');
        }

        return ApiResponse::error('Akses ditolak', 'Role tidak diizinkan', 403);
    }

    /**
     * Tahap 4 - Logout
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, 'Logout berhasil');
    }

    /**
     * Tahap 4 - User (Me)
     */
    public function user(Request $request)
    {
        
        $user = $request->user();

        if (!$user) {
            return ApiResponse::error(
                'Unauthorized',
                'User belum login atau token tidak valid',
                401
            );
        }

        return ApiResponse::success(
            $user,
            'Data user login'
        );

    }
}
