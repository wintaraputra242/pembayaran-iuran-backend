<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';

    protected $fillable = [
        'transaction_id',
        'nik',
        'nik_snapshot',
        'nama_warga_snapshot',
        'id_informasi_iuran',
        'tanggal_bayar',
        'total_bayar',
        'metode_bayar',
        'status_bayar',
        'jumlah_iuran_snapshot',
        'bulan',
        'bukti_pembayaran',
        'midtrans_order_id',
        'midtrans_transaction_id',
        'midtrans_va_number',
        'midtrans_qr_string',
        'midtrans_payment_type',
        'midtrans_raw_response',
        'processed_by'
    ];

    protected $casts = [
        'bulan' => 'array',
    ];

    // Relasi ke warga
    public function warga()
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik');
    }

    // Relasi ke informasi iuran
    public function informasiIuran()
    {
        return $this->belongsTo(InformasiIuran::class, 'id_informasi_iuran', 'id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
