<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #000;
            padding: 6px;
            text-align: left;
        }
        th {
            background: #eee;
        }
        .password {
            font-weight: bold;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>

<h3>DOKUMEN RAHASIA – CREDENTIAL REGU</h3>

<table>
    <thead>
        <tr>
            <th>No</th>
            <th>Nama Regu</th>
            <th>Username</th>
            <th>Password</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
        <tr>
            <td>{{ $row['no'] }}</td>
            <td>{{ $row['regu'] }}</td>
            <td>{{ $row['username'] }}</td>
            <td class="password">{{ $row['password'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<p style="margin-top:20px;font-size:10px">
    * Password hanya ditampilkan saat pertama kali dibuat.
</p>

</body>
</html>
