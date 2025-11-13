<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Regu extends Model
{
    use HasFactory;

    protected $table = 'regu';
    protected $primaryKey = 'id_regu';

    protected $fillable = [
        'nama_regu',
        'id_ketua', // foreign key ke tabel users
    ];

    // Relasi ke ketua regu
    public function ketua()
    {
        return $this->belongsTo(User::class, 'id_ketua');
    }

    // Relasi ke anggota regu
    public function anggota()
    {
        return $this->hasMany(AnggotaRegu::class, 'id_regu');
    }
}
