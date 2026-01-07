<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Regu;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->keyword . '%')
                ->orWhere('username', 'LIKE', '%' . $request->keyword . '%');
            });
        }
        
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $query->where('is_active', 1);
        $query->whereNot('role', 'warga');

        $limit = $request->get('limit', 10);

        $users = $query
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return ApiResponse::success(
            $users,
            'Data users berhasil diambil.',
            200
        );
    }

    public function downloadCredentialPdf()
    {
        $passwordPath = 'credentials/passwords.json';

        if (!Storage::exists($passwordPath)) {
            return ApiResponse::error(
                'File tidak ditemukan.',
                'Data password belum tersedia.',
                404
            );
        }

        $passwords = json_decode(
            Storage::get($passwordPath),
            true
        );

        $reguList = Regu::with('user')->orderBy('id')->get();

        $rows = [];
        foreach ($reguList as $index => $regu) {
            $key = 'regu_' . $regu->id;

            $rows[] = [
                'no' => $index + 1,
                'regu' => $regu->nama_regu,
                'username' => $passwords[$key]['username'] ?? '-',
                'password' => $passwords[$key]['password'] ?? '-',
            ];
        }

        $pdf = Pdf::loadView('pdf.credential-global-table', [
            'rows' => $rows,
        ]);

        return $pdf->download('credential-regu.pdf');
    }
}
