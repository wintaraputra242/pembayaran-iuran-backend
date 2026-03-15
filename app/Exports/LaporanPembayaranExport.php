<?php

namespace App\Exports;

use App\Models\Pembayaran;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnWidths;

class LaporanPembayaranExport implements FromCollection, WithHeadings, WithColumnWidths
{
    protected $filters;

    public function __construct($filters)
    {
        $this->filters = $filters;
    }

    public function collection(): Collection
    {
        $query = Pembayaran::query()
            ->with(['warga.anggotaRegu.regu', 'informasiIuran', 'processedBy']);

        if (!empty($this->filters['start_date']) && !empty($this->filters['end_date'])) {
            $query->whereBetween('tanggal_bayar', [
                $this->filters['start_date'],
                $this->filters['end_date']
            ]);
        }

        if (!empty($this->filters['jenis_iuran'])) {
            $query->whereHas('informasiIuran', function ($q) {
                $q->where('jenis_iuran', $this->filters['jenis_iuran']);
            });
        }

        if (!empty($this->filters['metode_bayar'])) {
            $query->where('metode_bayar', $this->filters['metode_bayar']);
        }

        if (!empty($this->filters['status_bayar'])) {
            $query->where('status_bayar', $this->filters['status_bayar']);
        }

        if (!empty($this->filters['regu'])) {
            $query->whereHas('warga.anggotaRegu', function ($q) {
                $q->where('id_regu', $this->filters['regu']);
            });
        }

        if (!empty($this->filters['informasi_iuran'])) {
            $query->where('id_informasi_iuran', $this->filters['informasi_iuran']);
        }

        return $query->orderBy('tanggal_bayar', 'desc')
            ->get()
            ->map(function ($item, $index) {

                $statusText = [
                    'pending' => 'Pending',
                    'waiting_payment' => 'Menunggu Pembayaran',
                    'paid' => 'Lunas',
                    'failed' => 'Gagal',
                    'expired' => 'Kadaluarsa',
                    'canceled' => 'Dibatalkan',
                    'manual' => 'Manual',
                ];

                $bulan = '';

                if ($item->bulan) {
                    $bulanList = is_array($item->bulan) ? $item->bulan : json_decode($item->bulan, true);

                    $bulanMap = [
                        1 => 'Jan', 2 => 'Feb', 3 => 'Mar',
                        4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                        7 => 'Jul', 8 => 'Agu', 9 => 'Sep',
                        10 => 'Okt', 11 => 'Nov', 12 => 'Des'
                    ];

                    $bulan = collect($bulanList)
                        ->map(fn($b) => $bulanMap[$b] ?? '')
                        ->join(', ');
                }

                return [
                    $index + 1,
                    $item->transaction_id,
                    $item->tanggal_bayar,
                    $item->nama_warga_snapshot ?? optional($item->warga)->nama_warga ?? '-',
                    $item->warga->anggotaRegu->regu->nama_regu ?? '-',
                    $item->judul_iuran_snapshot ?? optional($item->informasiIuran)->judul_iuran ?? '-',
                    $bulan,
                    ucfirst($item->metode_bayar),
                    $item->total_bayar,
                    $item->processedBy->name ?? '-',
                    $statusText[$item->status_bayar] ?? $item->status_bayar,
                ];
            });
    }

    public function headings(): array
    {
        return [
            'No',
            'ID Transaksi',
            'Tanggal Bayar',
            'Nama Warga',
            'Regu',
            'Judul Iuran',
            'Bulan Dibayar',
            'Metode Bayar',
            'Nominal',
            'Petugas',
            'Status',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 25,
            'C' => 18,
            'D' => 25,
            'E' => 15,
            'F' => 25,
            'G' => 50,
            'H' => 15,
            'I' => 18,
            'J' => 20,
            'K' => 15,
        ];
    }
}