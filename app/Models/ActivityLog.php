<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'id_user',
        'nama_user_snapshot',
        'action',
        'description',
        'ip_address',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        // withTrashed() agar log tetap bisa menampilkan info user
        // meski user sudah dihapus
        return $this->belongsTo(User::class, 'id_user')->withTrashed();
    }
}
