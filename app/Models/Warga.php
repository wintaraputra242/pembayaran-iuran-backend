<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warga extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'warga';

    protected $primaryKey = 'nik';
    public    $incrementing = false;
    protected $keyType      = 'string';

    protected $fillable = [
        'nik',
        'id_user',
        'nama_warga',
        'alamat',
        'no_hp',
        'status_keaktifan',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function anggotaRegu(): HasMany
    {
        return $this->hasMany(AnggotaRegu::class, 'nik', 'nik');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'nik', 'nik');
    }

    public function iuranSebagaiPenanggungJawab(): HasMany
    {
        return $this->hasMany(InformasiIuran::class, 'nik_penanggung_jawab', 'nik');
    }
}
