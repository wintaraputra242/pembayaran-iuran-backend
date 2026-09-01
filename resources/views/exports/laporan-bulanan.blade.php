<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Iuran Bulanan</title>
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
      padding: 5px 4px;
      text-align: center;
      font-size: 9px;
      border: 1px solid #1a252f;
    }
    table.data th.left {
      text-align: left;
    }
    table.data td {
      padding: 4px 4px;
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

    .ttd {
      width: 100%;
      margin-top: 30px;
    }
    .ttd table {
      width: 100%;
      border: none;
    }
    .ttd td {
      border: none;
      padding: 0;
      font-size: 10px;
    }
    .ttd .kolom-ttd {
      width: 200px;
      text-align: center;
    }
    .ttd .spasi-ttd {
      height: 50px;
    }
    .ttd .nama-ttd {
      font-weight: bold;
      text-decoration: underline;
    }
  </style>
</head>
<body>

  <!-- Kop -->
  <div class="kop">
    <h2>Banjar Trijata</h2>
    <p>Laporan Iuran Bulanan</p>
    <p><strong>{{ $iuran->judul_iuran }}</strong> &mdash; Periode {{ $iuran->periode ?? '-' }}</p>
  </div>

  <!-- Info Laporan -->
  <div class="info-laporan">
    <table>
      <tr>
        <td>Jenis Iuran</td>
        <td>: Bulanan</td>
      </tr>
      <tr>
        <td>Jumlah Iuran/Bulan</td>
        <td>: Rp {{ number_format($iuran->jumlah_iuran, 0, ',', '.') }}</td>
      </tr>
      <tr>
        <td>Tanggal Export</td>
        <td>: {{ now()->translatedFormat('d F Y, H:i') }} WITA</td>
      </tr>
      <tr>
        <td>Total Warga</td>
        <td>: {{ count($wargas) }} orang</td>
      </tr>
    </table>
  </div>

  <!-- Tabel -->
  <table class="data">
    <thead>
      <tr>
        <th style="width: 22px;">No</th>
        <th class="left" style="width: 130px;">Nama Warga</th>
        <th style="width: 70px;">Regu</th>
        @foreach ($bulanHeaders as $bulan)
          <th style="width: 36px;">{{ $bulan }}</th>
        @endforeach
        <th style="width: 40px;">Lunas</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($wargas as $index => $warga)
        @php
          $bulanBayar = collect($warga['bulan_dibayar'] ?? []);
          $lunas = $bulanBayar->count() >= 12;
        @endphp
        <tr>
          <td class="center">{{ $index + 1 }}</td>
          <td>{{ $warga['nama_warga'] }}</td>
          <td class="center">{{ $warga['regu'] ?? '-' }}</td>
          @foreach (range(1, 12) as $bulanNum)
            <td class="center">
              @if ($bulanBayar->contains($bulanNum))
                <span class="check">✓</span>
              @endif
            </td>
          @endforeach
          <td class="center">
            @if ($lunas)
              <span class="check">✓</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Summary -->
  <div class="summary">
    <p>
      Lunas: <strong>{{ collect($wargas)->filter(fn($w) => count($w['bulan_dibayar'] ?? []) >= 12)->count() }} warga</strong>
      &nbsp;|&nbsp;
      Belum Lunas: <strong>{{ collect($wargas)->filter(fn($w) => count($w['bulan_dibayar'] ?? []) < 12)->count() }} warga</strong>
    </p>
  </div>

  <!-- Tanda Tangan Pengurus -->
  <div class="ttd">
    <table>
      <tr>
        <td style="width: 60%;">&nbsp;</td>
        <td class="kolom-ttd" style="width: 40%;">
          <p>Denpasar, {{ now()->translatedFormat('d F Y') }}</p>
          <p>{{ $jabatanPengurus }},</p>
          @if ($ttdPengurus)
            <img src="{{ $ttdPengurus }}" style="height: 60px; margin: 2px 0;">
          @else
            <div class="spasi-ttd"></div>
          @endif
          <p class="nama-ttd">{{ $pengurus }}</p>
        </td>
      </tr>
    </table>
  </div>

  <div class="footer">
    Dicetak otomatis oleh sistem &mdash; {{ now()->translatedFormat('d F Y H:i') }}
  </div>

</body>
</html>