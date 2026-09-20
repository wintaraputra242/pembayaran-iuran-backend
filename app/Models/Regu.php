<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Regu extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'regu';

    protected $fillable = ['nama_regu', 'status_keaktifan', 'id_user'];

    public function ketuaRegu(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function anggotaRegu(): HasMany
    {
        return $this->hasMany(AnggotaRegu::class, 'id_regu');
    }

    public function anggotaAktif(): HasMany
    {
        return $this->hasMany(AnggotaRegu::class, 'id_regu')
            ->where('status_keaktifan', 'aktif');
    }
}
