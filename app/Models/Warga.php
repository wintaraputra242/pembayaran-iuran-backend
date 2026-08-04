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
        'tanggal_nonaktif', // ← tambah ini
        'tanggal_bergabung',
    ];

    protected $casts = [
        'tanggal_bergabung' => 'date',
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

    // app/Models/Warga.php — tambahkan method ini
    public function hitungRentangBulanWajib(int $tahunPeriode): array
    {
        $bulanMulai    = 1;
        $bulanMaksimal = 12;

        $tanggalBergabung = $this->tanggal_bergabung
            ? \Carbon\Carbon::parse($this->tanggal_bergabung)
            : $this->created_at;

        $tahunBergabung = (int) $tanggalBergabung->format('Y');
        $bulanBergabung = (int) $tanggalBergabung->format('n');

        if ($tahunBergabung === $tahunPeriode) {
            $bulanMulai = $bulanBergabung;
        } elseif ($tahunBergabung > $tahunPeriode) {
            $bulanMulai = 13;
        }

        if ($this->status_keaktifan === 'tidak_aktif' && $this->tanggal_nonaktif) {
            $tglNonaktif   = \Carbon\Carbon::parse($this->tanggal_nonaktif);
            $tahunNonaktif = (int) $tglNonaktif->format('Y');
            $bulanNonaktif = (int) $tglNonaktif->format('n');

            if ($tahunNonaktif === $tahunPeriode) {
                $bulanMaksimal = $bulanNonaktif;
            } elseif ($tahunNonaktif < $tahunPeriode) {
                $bulanMaksimal = 0;
            }
        }

        return [$bulanMulai, $bulanMaksimal];
    }
}
