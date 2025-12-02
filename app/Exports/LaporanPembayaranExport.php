<?php

namespace App\Exports;

use App\Models\Pembayaran;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LaporanPembayaranExport implements FromCollection, WithHeadings, WithMapping
{
    protected $request;

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $query = Pembayaran::with(['warga', 'informasiIuran']);

        // Filter NIK
        if ($this->request->filled('nik')) {
            $query->where('nik', $this->request->nik);
        }

        // Filter status bayar
        if ($this->request->filled('status_bayar')) {
            $query->where('status_bayar', $this->request->status_bayar);
        }

        // Filter tanggal bayar
        if ($this->request->filled('from') && $this->request->filled('to')) {
            $query->whereBetween('tanggal_bayar', [
                $this->request->from,
                $this->request->to
            ]);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'NIK',
            'Nama Warga',
            'Jenis Iuran',
            'Bulan',
            'Jumlah Iuran',
            'Total Bayar',
            'Tanggal Bayar',
            'Metode Bayar',
            'Status'
        ];
    }

    public function map($p): array
    {
        return [
            $p->nik,
            $p->nama_warga_snapshot,
            $p->informasiIuran->nama_iuran ?? '-',
            $p->bulan ?? '-',
            $p->jumlah_iuran_snapshot,
            $p->total_bayar,
            $p->tanggal_bayar,
            $p->metode_bayar,
            $p->status_bayar,
        ];
    }
}
