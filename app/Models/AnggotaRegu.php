<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnggotaRegu extends Model
{
    use HasFactory;

    protected $table = 'anggota_regu';
    protected $primaryKey = 'id_anggota_regu';

    protected $fillable = [
        'id_regu',
        'id_warga',
        'tanggal_gabung',
    ];

    public function regu()
    {
        return $this->belongsTo(Regu::class, 'id_regu');
    }

    public function warga()
    {
        return $this->belongsTo(Warga::class, 'id_warga');
    }
}
