<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Regu;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('username', 'LIKE', '%' . $request->keyword . '%');
            });
        }

        $allowedRoles = ['admin', 'ketua_regu'];
        if ($request->filled('role') && in_array($request->role, $allowedRoles)) {
            $query->where('role', $request->role);
        } else {
            $query->whereIn('role', $allowedRoles);
        }

        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        $limit = $request->get('limit', 10);

        $users = $query
            ->select(['id', 'name', 'username', 'role', 'is_active', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return ApiResponse::success($users, 'Data users berhasil diambil.');
    }

    public function show(int $id): JsonResponse
    {
        $user = User::select(['id', 'name', 'username', 'role', 'is_active', 'created_at'])
            ->find($id);

        if (!$user) {
            return ApiResponse::error('Not Found', 'User tidak ditemukan.', 404);
        }

        return ApiResponse::success($user, 'Detail user berhasil diambil.');
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return ApiResponse::error('Not Found', 'User tidak ditemukan.', 404);
        }

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'username'  => ['sometimes', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'password'  => 'sometimes|string|min:6',
            'role'      => ['sometimes', Rule::in(['admin', 'ketua_regu'])],
            'is_active' => 'sometimes|boolean',
        ], [
            'name.string'       => 'Nama harus berupa teks.',
            'name.max'          => 'Nama tidak boleh lebih dari 255 karakter.',
            'username.string'   => 'Username harus berupa teks.',
            'username.max'      => 'Username tidak boleh lebih dari 255 karakter.',
            'username.unique'   => 'Username sudah digunakan oleh pengguna lain.',
            'password.string'   => 'Password harus berupa teks.',
            'password.min'      => 'Password harus minimal 6 karakter.',
            'role.in'           => 'Role yang dipilih tidak valid. Pilih antara admin atau ketua regu.',
            'is_active.boolean' => 'Status aktif harus berupa pilihan true atau false (boolean).',
        ]);

        $plainPassword = null;

        if (isset($validated['password'])) {
            $plainPassword = $validated['password'];
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // Update password.json jika user adalah ketua_regu dan password diupdate
        if ($plainPassword && $user->role === 'ketua_regu') {
            $regu = DB::table('regu')->where('id_user', $user->id)->first();

            if ($regu) {
                $this->saveCredential(
                    $regu->id,
                    $validated['username'] ?? $user->username,
                    $plainPassword
                );
            }
        }

        $this->writeLog(
            Auth::user()?->id,
            Auth::user()?->name,
            'update',
            "Update data user ID {$user->id} ({$user->username})",
            $request
        );

        return ApiResponse::success(
            $user->only(['id', 'name', 'username', 'role', 'is_active']),
            'Data user berhasil diperbarui.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return ApiResponse::error('Not Found', 'User tidak ditemukan.', 404);
        }

        if ($user->id === Auth::user()?->id) {
            return ApiResponse::error('Forbidden', 'Tidak dapat menghapus akun yang sedang digunakan.', 403);
        }

        $user->tokens()->delete();

        $user->delete();

        $this->writeLog(
            Auth::user()?->id,
            Auth::user()?->name,
            'delete',
            "Soft delete user ID {$user->id} ({$user->username})",
            $request
        );

        return ApiResponse::success(null, 'User berhasil dihapus.');
    }

    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return ApiResponse::error('Not Found', 'User tidak ditemukan.', 404);
        }

        if ($user->id === Auth::user()?->id) {
            return ApiResponse::error('Forbidden', 'Tidak dapat menonaktifkan akun yang sedang digunakan.', 403);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        if (!$user->is_active) {
            $user->tokens()->delete();
        }

        $status = $user->is_active ? 'diaktifkan' : 'dinonaktifkan';

        $this->writeLog(
            Auth::user()?->id,
            Auth::user()?->name,
            'update',
            "User ID {$user->id} ({$user->username}) {$status}",
            $request
        );

        return ApiResponse::success(
            $user->only(['id', 'name', 'username', 'role', 'is_active']),
            "User berhasil {$status}."
        );
    }

    public function downloadCredentialPdf(Request $request): mixed
    {
        $passwordPath = 'credentials/passwords.json';

        if (!Storage::exists($passwordPath)) {
            return ApiResponse::error(
                'File tidak ditemukan.',
                'Data password belum tersedia.',
                404
            );
        }

        $raw = Storage::get($passwordPath);
        $passwords = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ApiResponse::error(
                'File rusak.',
                'Format data password tidak valid.',
                500
            );
        }

        $reguList = Regu::with('ketuaRegu')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        // Guard: belum ada data regu sama sekali — jangan lanjut generate PDF,
        // langsung kasih pesan yang jelas ke user.
        if ($reguList->isEmpty()) {
            return ApiResponse::error(
                'Data regu belum tersedia.',
                'Belum ada data regu yang terdaftar, sehingga PDF kredensial tidak dapat dibuat.',
                404
            );
        }

        $rows = $reguList->map(function ($regu, $index) use ($passwords) {
            $key = 'regu_' . $regu->id;

            return [
                'no'       => $index + 1,
                'regu'     => $regu->nama_regu,
                'username' => $passwords[$key]['username'] ?? '-',
                'password' => $passwords[$key]['password'] ?? '-',
                'ketua'    => $regu->ketuaRegu?->name ?? '(belum ditentukan)',
            ];
        })->toArray();

        try {
            $pdf = Pdf::loadView('pdf.credential-global-table', ['rows' => $rows]);
            $output = $pdf->download('credential-regu.pdf');
        } catch (\Throwable $e) {
            Log::error('Gagal generate PDF kredensial regu: ' . $e->getMessage());

            return ApiResponse::error(
                'Gagal membuat PDF.',
                'Terjadi kesalahan saat membuat file PDF kredensial. Silakan coba lagi.',
                500
            );
        }

        $this->writeLog(
            Auth::user()?->id,
            Auth::user()?->name,
            'download',
            'Mengunduh PDF kredensial akun regu',
            $request
        );

        return $output;
    }

    private function writeLog(
        int|string|null $userId,
        ?string $namaUser,
        string  $action,
        string  $description,
        Request $request
    ): void {
        try {
            ActivityLog::create([
                'id_user'            => $userId,
                'nama_user_snapshot' => $namaUser,
                'action'             => $action,
                'description'        => $description,
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Gagal menulis activity log: {$e->getMessage()}");
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
}
