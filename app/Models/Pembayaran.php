<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pembayaran extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pembayaran';

    protected $fillable = [
        'nik',
        'id_informasi_iuran',
        'nik_snapshot',
        'nama_warga_snapshot',
        'jumlah_iuran_snapshot',
        'bulan',
        'tanggal_bayar',
        'total_bayar',
        'metode_bayar',
        'status_bayar',
        'submitted_at',
        'processed_by',
        'validated_by',
        'validated_at',
        'rejection_reason',
        'bukti_pembayaran',
        'note',
    ];

    protected $casts = [
        'bulan'        => 'array',
        'tanggal_bayar'=> 'date',
        'submitted_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    const METODE_CASH     = 'cash';
    const METODE_QRIS     = 'qris';
    const METODE_TRANSFER = 'transfer';

    public function warga(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik')->withTrashed();
    }

    public function informasiIuran(): BelongsTo
    {
        return $this->belongsTo(InformasiIuran::class, 'id_informasi_iuran')->withTrashed();
    }

    public function diprosesOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by')->withTrashed();
    }

    public function divalidasiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status_bayar === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status_bayar === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status_bayar === self::STATUS_REJECTED;
    }
}