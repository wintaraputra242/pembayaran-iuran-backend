<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnggotaRegu extends Model
{
  use HasFactory, SoftDeletes;

  protected $table = 'anggota_regu';

  protected $fillable = ['id_regu', 'nik', 'status_keaktifan', 'is_leader'];

  public function regu(): BelongsTo
  {
    return $this->belongsTo(Regu::class, 'id_regu');
  }

  public function warga(): BelongsTo
  {
    return $this->belongsTo(Warga::class, 'nik', 'nik');
  }
}
