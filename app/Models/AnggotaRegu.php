<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnggotaRegu extends Model
{
    use HasFactory;

    protected $table = 'anggota_regu';

    protected $fillable = [
        'id_regu',
        'nik',
        'status_keaktifan',
        'is_leader'
    ];

    // Relasi ke regu
    public function regu()
    {
        return $this->belongsTo(Regu::class, 'id_regu');
    }

    // Relasi ke warga
    public function warga()
    {
        return $this->belongsTo(Warga::class, 'nik', 'nik');
    }
}
