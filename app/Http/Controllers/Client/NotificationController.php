<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::user()->id;

        $query = Notification::query()
            ->where('user_id', $userId);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::success(
            $data,
            'Data notifikasi berhasil diambil.'
        );
    }

    public function unreadCount()
    {
        $userId = Auth::user()->id;

        $count = Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return ApiResponse::success(
            ['unread_count' => $count],
            'Jumlah notifikasi belum dibaca.'
        );
    }

    public function markAsRead($id)
    {
        $userId = Auth::user()->id;

        $notification = Notification::where('user_id', $userId)->find($id);

        if (!$notification) {
            return ApiResponse::error('Notifikasi tidak ditemukan.', null, 404);
        }

        $notification->update(['is_read' => true]);

        return ApiResponse::success(
            $notification,
            'Notifikasi ditandai sudah dibaca.'
        );
    }

    public function markAllAsRead()
    {
        $userId = Auth::user()->id;

        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return ApiResponse::success(
            null,
            'Semua notifikasi ditandai sudah dibaca.'
        );
    }
}
