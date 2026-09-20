<?php

namespace App\Imports;

use App\Models\Warga;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class WargaImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            if (empty($row['nik']) || empty($row['nama_warga'])) {
                continue;
            }

            if (Warga::where('nik', $row['nik'])->exists()) {
                continue;
            }

            Warga::create([
                'nik' => $row['nik'],
                'nama_warga' => Str::upper($row['nama_warga']),
                'alamat' => $row['alamat'] ?? '',
                'hp' => $row['hp'] ?? '',
                'id_user' => null,
                'status_keaktifan' => 'aktif',
            ]);
        }
    }
}
