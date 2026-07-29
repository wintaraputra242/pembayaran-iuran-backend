<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $table = 'notification_logs';

    protected $fillable = [
        'nik',
        'id_informasi_iuran',
        'type',
        'periode',
        'is_sent',
        'sent_at',
        'message',
    ];

    protected $casts = [
        'is_sent'  => 'boolean',
        'sent_at'  => 'datetime',
    ];

    public function warga(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik');
    }

    public function informasiIuran(): BelongsTo
    {
        return $this->belongsTo(InformasiIuran::class, 'id_informasi_iuran');
    }
}