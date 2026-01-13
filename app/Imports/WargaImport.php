<?php

namespace App\Imports;

use App\Models\Warga;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

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
                'nik'              => $row['nik'],
                'nama_warga'       => $row['nama_warga'],
                'alamat'           => $row['alamat'] ?? '',
                'hp'               => $row['hp'] ?? '',
                'id_user'          => null,
                'status_keaktifan' => 'aktif',
            ]);
        }
    }
}
