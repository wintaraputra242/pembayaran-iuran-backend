<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Notification::query()
            ->where('user_id', auth()->id());

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
        $count = Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();

        return ApiResponse::success(
            [
                'unread_count' => $count
            ],
            'Jumlah notifikasi belum dibaca.'
        );
    }

    public function markAsRead($id)
    {
        $notification = Notification::findOrFail($id);

        $notification->update([
            'is_read' => true
        ]);

        return ApiResponse::success(
            $notification,
            'Notifikasi ditandai sudah dibaca.'
        );
    }

    public function markAllAsRead()
    {
        Notification::where('is_read', false)
            ->update(['is_read' => true]);

        return ApiResponse::success(
            null,
            'Semua notifikasi ditandai sudah dibaca.'
        );
    }
}