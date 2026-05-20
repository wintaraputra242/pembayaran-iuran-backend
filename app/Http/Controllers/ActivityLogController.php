<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = ActivityLog::with([
            'user:id,name',
        ]);

        if ($user->role === 'ketua_regu') {
            $query->where('id_user', $user->id);
        }

        if ($request->filled('user') && $user->role === 'admin') {
            $query->where('nama_user_snapshot', 'like', '%' . $request->user . '%');
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end   = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start, $end]);
        }

        $data = $query
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($data, 'Data aktivitas berhasil diambil.');
    }

    public function show($id)
    {
        $log = ActivityLog::with([
            'user:id,name',
        ])->find($id);

        if (!$log) {
            return ApiResponse::error('Data tidak ditemukan.', 'Activity log tidak ditemukan.', 404);
        }

        return ApiResponse::success($log, 'Detail aktivitas berhasil diambil.');
    }
}