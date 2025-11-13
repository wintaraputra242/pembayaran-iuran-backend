<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Regu extends Model
{
    use HasFactory;

    protected $table = 'regu';

    protected $fillable = [
        'nama_regu',
    ];

    // Relasi ke anggota regu
    public function anggotaRegu()
    {
        return $this->hasMany(AnggotaRegu::class, 'id_regu');
    }
}
