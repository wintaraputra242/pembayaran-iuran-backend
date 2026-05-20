<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * Dipanggil SEBELUM $user->delete() dieksekusi.
     *
     * Saat user dihapus:
     * - Hard delete device tokens (tidak relevan lagi)
     * - Soft delete notifikasi milik user ini
     * - Regu yang diketuai: id_user → null via FK nullOnDelete
     * - Warga yang terhubung: id_user → null via FK nullOnDelete
     * - activity_logs: id_user → null via FK nullOnDelete
     */
    public function deleting(User $user): void
    {
        // Hard delete device tokens (FCM token tidak berguna setelah user dihapus)
        $user->devices()->delete();
 
        // Soft delete notifikasi milik user ini
        $user->notifications()
             ->whereNull('deleted_at')
             ->each(fn($notif) => $notif->delete());
    }
 
    /**
     * Dipanggil SETELAH restore() selesai.
     *
     * Restore notifikasi yang ikut ter-soft delete saat user dihapus.
     */
    public function restored(User $user): void
    {
        // Restore notifikasi yang dihapus bersamaan dengan user
        // (toleransi 5 detik dari waktu user dihapus)
        $user->notifications()
             ->withTrashed()
             ->whereBetween('deleted_at', [
                 $user->deleted_at->subSeconds(5),
                 $user->deleted_at->addSeconds(5),
             ])
             ->each(fn($notif) => $notif->restore());
    }
}

