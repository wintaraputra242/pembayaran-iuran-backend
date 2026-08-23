<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Gabungan Iuran Kematian</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'DejaVu Sans', Arial, sans-serif;
      font-size: 10px;
      color: #1a1a1a;
    }

    .kop {
      text-align: center;
      border-bottom: 2px solid #1a1a1a;
      padding-bottom: 8px;
      margin-bottom: 14px;
    }
    .kop h2 {
      font-size: 14px;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .kop p {
      font-size: 10px;
      margin-top: 2px;
      color: #444;
    }

    .info-laporan {
      margin-bottom: 12px;
    }
    .info-laporan table {
      border: none;
    }
    .info-laporan td {
      padding: 2px 4px;
      font-size: 10px;
      border: none;
    }
    .info-laporan td:first-child {
      width: 130px;
      color: #555;
    }

    table.data {
      width: 100%;
      border-collapse: collapse;
      margin-top: 6px;
    }
    table.data th {
      background-color: #2c3e50;
      color: #ffffff;
      padding: 4px 3px;
      text-align: center;
      font-size: 8px;
      border: 1px solid #1a252f;
      vertical-align: bottom;
    }
    table.data th.left {
      text-align: left;
    }
    table.data th .col-nama {
      display: block;
      font-weight: bold;
    }
    table.data th .col-tanggal {
      display: block;
      font-weight: normal;
      color: #cfd8dc;
      margin-top: 1px;
    }
    table.data td {
      padding: 4px 3px;
      border: 1px solid #ccc;
      font-size: 9px;
      vertical-align: middle;
    }
    table.data td.center {
      text-align: center;
    }
    table.data tr:nth-child(even) td {
      background-color: #f5f5f5;
    }

    .check {
      color: #27ae60;
      font-weight: bold;
      font-size: 11px;
    }

    .footer {
      margin-top: 20px;
      font-size: 9px;
      color: #777;
      text-align: right;
    }

    .summary {
      margin-top: 10px;
      font-size: 9px;
      color: #444;
    }

    .empty {
      text-align: center;
      padding: 24px 0;
      color: #777;
      font-size: 10px;
    }
  </style>
</head>
<body>

  <!-- Kop -->
  <div class="kop">
    <h2>Banjar Trijata</h2>
    <p>Laporan Gabungan Iuran Kematian</p>
    <p><strong>{{ $startDate->translatedFormat('d F Y') }} &mdash; {{ $endDate->translatedFormat('d F Y') }}</strong></p>
  </div>

  <!-- Info Laporan -->
  <div class="info-laporan">
    <table>
      <tr>
        <td>Jenis Iuran</td>
        <td>: Kematian</td>
      </tr>
      <tr>
        <td>Rentang Tanggal</td>
        <td>: {{ $startDate->translatedFormat('d F Y') }} &ndash; {{ $endDate->translatedFormat('d F Y') }}</td>
      </tr>
      <tr>
        <td>Tanggal Export</td>
        <td>: {{ now()->translatedFormat('d F Y, H:i') }} WITA</td>
      </tr>
      <tr>
        <td>Total Informasi Iuran</td>
        <td>: {{ count($iurans) }} data</td>
      </tr>
      <tr>
        <td>Total Warga</td>
        <td>: {{ count($wargas) }} orang</td>
      </tr>
    </table>
  </div>

  @if (count($iurans) === 0)
    <div class="empty">
      Tidak ada data informasi iuran kematian pada rentang tanggal yang dipilih.
    </div>
  @else
    <!-- Tabel -->
    <table class="data">
      <thead>
        <tr>
          <th style="width: 22px;">No</th>
          <th class="left" style="width: 130px;">Nama Warga</th>
          <th style="width: 70px;">Regu</th>
          @foreach ($iurans as $iuran)
            <th style="width: 46px;">
              <span class="col-nama">{{ $iuran->nama_warga_meninggal ?? $iuran->judul_iuran }}</span>
              <span class="col-tanggal">{{ $iuran->created_at->translatedFormat('d/m/y') }}</span>
            </th>
          @endforeach
          <th style="width: 40px;">Jumlah Bayar</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($wargas as $index => $warga)
          <tr>
            <td class="center">{{ $index + 1 }}</td>
            <td>{{ $warga['nama_warga'] }}</td>
            <td class="center">{{ $warga['regu'] ?? '-' }}</td>
            @foreach ($iurans as $iuran)
              <td class="center">
                @if ($warga['status_per_iuran'][$iuran->id] ?? false)
                  <span class="check">✓</span>
                @endif
              </td>
            @endforeach
            <td class="center">{{ $warga['jumlah_bayar'] }}/{{ count($iurans) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <!-- Summary -->
    <div class="summary">
      <p>
        Lunas Semua: <strong>{{ collect($wargas)->filter(fn($w) => $w['jumlah_bayar'] >= count($iurans))->count() }} warga</strong>
        &nbsp;|&nbsp;
        Belum Lunas Semua: <strong>{{ collect($wargas)->filter(fn($w) => $w['jumlah_bayar'] < count($iurans))->count() }} warga</strong>
      </p>
    </div>
  @endif

  <div class="footer">
    Dicetak otomatis oleh sistem &mdash; {{ now()->translatedFormat('d F Y H:i') }}
  </div>

</body>
</html>
