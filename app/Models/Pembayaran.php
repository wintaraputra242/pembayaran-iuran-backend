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
        'transaction_id',
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
        'processed_by',
        'midtrans_order_id',
        'midtrans_transaction_id',
        'midtrans_va_number',
        'midtrans_qr_string',
        'midtrans_payment_type',
        'midtrans_raw_response',
        'bukti_pembayaran',
    ];

    protected $casts = [
        'bulan'                 => 'array',
        'midtrans_raw_response' => 'array',
        'tanggal_bayar'         => 'date',
    ];

    public function warga(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik')->withTrashed();
    }

    public function informasiIuran(): BelongsTo
    {
        return $this->belongsTo(InformasiIuran::class, 'id_informasi_iuran')->withTrashed();
    }

    public function diprosesoleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by')->withTrashed();
    }
}
