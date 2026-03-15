<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::with([
            'user:id,name,role'
        ]);

        // filter nama user
        if ($request->filled('user')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->user . '%');
            });
        }

        // filter action
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // filter tanggal
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start, $end]);
        }

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::success(
            $data,
            'Data aktivitas berhasil diambil.'
        );
    }

    public function show($id)
    {
        $log = ActivityLog::with([
            'user:id,name,role'
        ])->find($id);

        if (!$log) {
            return ApiResponse::error(
                'Data tidak ditemukan.',
                'Activity log tidak ditemukan.',
                404
            );
        }

        return ApiResponse::success(
            $log,
            'Detail aktivitas berhasil diambil.'
        );
    }
}
