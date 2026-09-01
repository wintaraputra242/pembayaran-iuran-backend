<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Warga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel as FacadesExcel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Str;

class WargaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Warga::query();

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('nik', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama_warga', 'LIKE', "%{$keyword}%")
                    ->orWhere('no_hp', 'LIKE', "%{$keyword}%");
            });
        }

        if ($request->filled('status_keaktifan')) {
            $query->where('status_keaktifan', $request->status_keaktifan);
        }

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        $limit = $request->get('limit', 10);

        $warga = $query
            ->orderByRaw('deleted_at IS NOT NULL ASC')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return ApiResponse::success($warga, 'Data warga berhasil diambil.');
    }

    public function show(string $nik): JsonResponse
    {
        $warga = Warga::withTrashed()->find($nik);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($warga, 'Detail warga berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik'               => 'required|digits:16|unique:warga,nik',
            'nama_warga'        => 'required|string|max:100',
            'alamat'            => 'required|string',
            'no_hp'             => [
                'required',
                'string',
                'max:20',
                'unique:warga,no_hp',
                'unique:users,no_hp',
            ],
            'tanggal_bergabung' => 'nullable|date',
        ], [
            'nik.required'        => 'NIK wajib diisi.',
            'nik.digits'   => 'NIK harus tepat 16 digit angka.',
            'nik.unique'          => 'NIK sudah terdaftar.',
            'nama_warga.required' => 'Nama warga wajib diisi.',
            'nama_warga.string'   => 'Nama warga harus berupa teks.',
            'nama_warga.max'      => 'Nama warga maksimal 100 karakter.',
            'alamat.required'     => 'Alamat wajib diisi.',
            'alamat.string'       => 'Alamat harus berupa teks.',
            'no_hp.required'      => 'Nomor HP wajib diisi.',
            'no_hp.string'        => 'Nomor HP harus berupa teks.',
            'no_hp.max'           => 'Nomor HP maksimal 20 karakter.',
            'no_hp.unique' => 'Nomor HP sudah digunakan oleh warga lain.',
            'tanggal_bergabung.date' => 'Tanggal bergabung harus berupa tanggal yang valid.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $user = User::create([
                'name'      => $validator->validated()['nama_warga'],
                'username'  => $request->nik,
                'no_hp'     => $request->no_hp,
                'password'  => null,
                'role'      => 'warga',
                'is_active' => true,
            ]);

            $warga = Warga::create([
                ...$validator->validated(),
                'id_user'          => $user->id,
                'status_keaktifan' => 'aktif',
            ]);

            $this->writeLog('create', "Menambahkan data warga baru dengan NIK {$request->nik}", $request);

            DB::commit();

            try {
                app(\App\Services\IuranNotificationService::class)->kirimPesanKeWarga(
                    $warga,
                    'Akun Anda Telah Dibuat 🎉',
                    "Selamat datang, {$warga->nama_warga}. Akun Anda telah didaftarkan oleh pengurus dengan NIK {$warga->nik}. Anda dapat login menggunakan NIK sebagai username.",
                    'akun'
                );
            } catch (\Throwable $e) {
                Log::warning("Gagal mengirim notifikasi akun warga baru: {$e->getMessage()}");
            }

            return ApiResponse::success(null, 'Data warga berhasil ditambahkan.', 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $nik): JsonResponse
    {
        $warga = Warga::find($nik);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_warga'        => 'sometimes|required|string|max:100',
            'alamat'            => 'sometimes|required|string',
            'no_hp'             => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('warga', 'no_hp')->ignore($nik, 'nik'),
                Rule::unique('users', 'no_hp')->ignore($warga->id_user),
            ],
            'tanggal_bergabung' => 'nullable|date',
        ], [
            'nama_warga.required' => 'Nama warga wajib diisi.',
            'nama_warga.max'      => 'Nama warga maksimal 100 karakter.',
            'alamat.required'     => 'Alamat wajib diisi.',
            'no_hp.max'           => 'Nomor HP maksimal 20 karakter.',
            'no_hp.unique'        => 'Nomor HP sudah digunakan oleh warga lain.',
            'tanggal_bergabung.date' => 'Tanggal bergabung harus berupa tanggal yang valid.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $warga->update($validator->validated());

            if ($warga->user) {
                $warga->user->update([
                    'name'  => $request->nama_warga ?? $warga->nama_warga,
                    'no_hp' => $request->no_hp,
                ]);
            }

            DB::commit();

            $this->writeLog('update', "Memperbarui data warga NIK {$warga->nik}", $request);

            return ApiResponse::success(null, 'Data warga berhasil diperbarui.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }


    public function destroy(Request $request, string $nik): JsonResponse
    {
        $warga = Warga::find($nik);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        DB::beginTransaction();

        try {
            $warga->status_keaktifan = 'tidak_aktif';
            $warga->save();
            $warga->delete();

            if ($warga->id_user) {
                User::find($warga->id_user)?->delete();
            }

            $this->writeLog('delete', "Soft delete warga NIK {$warga->nik} ({$warga->nama_warga})", $request);

            DB::commit();

            return ApiResponse::success(null, 'Data warga berhasil dinonaktifkan.');
        } catch (ValidationException $e) {
            DB::rollBack();
            return ApiResponse::error(
                'Tidak dapat menghapus warga.',
                collect($e->errors())->flatten()->first(),
                422
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }


    public function updateStatus(Request $request, string $nik): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status_keaktifan' => ['required', Rule::in(['aktif', 'tidak_aktif'])],
        ], [
            'status_keaktifan.required' => 'Status keaktifan wajib diisi.',
            'status_keaktifan.in'       => 'Status keaktifan hanya boleh: aktif atau tidak_aktif.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::withTrashed()->find($nik);

        if (!$warga) {
            return ApiResponse::error('Warga tidak ditemukan.', null, 404);
        }

        $statusBaru = $request->status_keaktifan;

        if ($warga->trashed() && $statusBaru === 'aktif') {
            $warga->restore();
        }

        $warga->status_keaktifan = $statusBaru;

        // Set tanggal_nonaktif otomatis
        if ($statusBaru === 'tidak_aktif') {
            $warga->tanggal_nonaktif = now()->toDateString();
        } else {
            // Reset tanggal_nonaktif kalau diaktifkan kembali
            $warga->tanggal_nonaktif = null;
        }

        $warga->save();

        $this->writeLog(
            'update',
            "Mengubah status warga NIK {$warga->nik} ({$warga->nama_warga}) menjadi {$statusBaru}",
            $request
        );

        try {
            $pesan = $statusBaru === 'aktif'
                ? "Status keanggotaan Anda telah diaktifkan kembali oleh pengurus."
                : "Status keanggotaan Anda telah dinonaktifkan oleh pengurus.";

            app(\App\Services\IuranNotificationService::class)->kirimPesanKeWarga(
                $warga,
                'Status Keanggotaan Diperbarui',
                $pesan,
                'status_warga'
            );
        } catch (\Throwable $e) {
            Log::warning("Gagal mengirim notifikasi status warga: {$e->getMessage()}");
        }

        return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');
    }

    public function importExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:5120',
        ]);

        try {
            $rows = FacadesExcel::toArray([], $request->file('file'));

            $data = array_slice($rows[0], 1);

            $maxRows = 1000;
            if (count($data) > $maxRows) {
                return ApiResponse::error(
                    'Terlalu banyak data.',
                    "Maksimal {$maxRows} baris per import. Data Anda memiliki " . count($data) . " baris.",
                    422
                );
            }

            $inserted = 0;
            $updated  = 0;
            $skipped  = 0;
            $errors   = [];

            foreach ($data as $rowIndex => $row) {
                $lineNum = $rowIndex + 2;

                $nik              = trim((string) ($row[0] ?? ''));
                $nama             = trim((string) ($row[1] ?? ''));
                $alamat           = trim((string) ($row[2] ?? ''));
                $hp               = trim((string) ($row[3] ?? ''));
                $tanggalBergabung = trim((string) ($row[4] ?? ''));

                if (!$nik || !$nama) {
                    $skipped++;
                    $errors[] = "Baris {$lineNum}: NIK dan Nama wajib diisi.";
                    continue;
                }

                if (strlen($nik) !== 16 || !ctype_digit($nik)) {
                    $skipped++;
                    $errors[] = "Baris {$lineNum}: NIK '{$nik}' harus tepat 16 digit angka.";
                    continue;
                }

                $parsedTanggalBergabung = null;
                if ($tanggalBergabung) {
                    try {
                        $parsedTanggalBergabung = \Carbon\Carbon::parse($tanggalBergabung)->format('Y-m-d');
                    } catch (\Throwable $e) {
                        $skipped++;
                        $errors[] = "Baris {$lineNum}: Format tanggal bergabung '{$tanggalBergabung}' tidak valid, dilewati.";
                        continue;
                    }
                }

                $existingWarga = Warga::withTrashed()->where('nik', $nik)->first();

                try {
                    if ($existingWarga) {
                        // Cek collision no_hp ke warga/user LAIN (bukan dirinya sendiri) sebelum update
                        if ($hp) {
                            $hpUsedByOtherWarga = Warga::withTrashed()
                                ->where('no_hp', $hp)
                                ->where('nik', '!=', $nik)
                                ->exists();

                            $hpUsedByOtherUser = User::withTrashed()
                                ->where('no_hp', $hp)
                                ->where('id', '!=', $existingWarga->id_user)
                                ->exists();

                            if ($hpUsedByOtherWarga || $hpUsedByOtherUser) {
                                $skipped++;
                                $errors[] = "Baris {$lineNum}: No HP '{$hp}' sudah digunakan warga/user lain, data NIK '{$nik}' dilewati (tidak diupdate).";
                                continue;
                            }
                        }

                        // MODE UPDATE — field kosong di Excel tidak menimpa data lama
                        DB::transaction(function () use ($existingWarga, $nama, $alamat, $hp, $parsedTanggalBergabung) {
                            $updateData = [];

                            if ($nama)   $updateData['nama_warga'] = Str::upper($nama);
                            if ($alamat) $updateData['alamat'] = $alamat;
                            if ($hp)     $updateData['no_hp'] = $hp;
                            if ($parsedTanggalBergabung) $updateData['tanggal_bergabung'] = $parsedTanggalBergabung;

                            if (!empty($updateData)) {
                                $existingWarga->update($updateData);

                                if ($existingWarga->user && (isset($updateData['nama_warga']) || isset($updateData['no_hp']))) {
                                    $existingWarga->user->update([
                                        'name'  => $updateData['nama_warga'] ?? $existingWarga->user->name,
                                        'no_hp' => $updateData['no_hp'] ?? $existingWarga->user->no_hp,
                                    ]);
                                }
                            }
                        });

                        $updated++;
                    } else {
                        $existsInUsers = User::withTrashed()->where('username', $nik)->exists();

                        if ($existsInUsers) {
                            $skipped++;
                            $errors[] = "Baris {$lineNum}: NIK '{$nik}' sudah digunakan sebagai username user lain, dilewati.";
                            continue;
                        }

                        // Cek collision no_hp untuk data BARU juga
                        if ($hp) {
                            $hpUsed = Warga::withTrashed()->where('no_hp', $hp)->exists()
                                || User::withTrashed()->where('no_hp', $hp)->exists();

                            if ($hpUsed) {
                                $skipped++;
                                $errors[] = "Baris {$lineNum}: No HP '{$hp}' sudah digunakan warga lain, dilewati.";
                                continue;
                            }
                        }

                        // MODE INSERT — data baru
                        DB::transaction(function () use ($nik, $nama, $alamat, $hp, $parsedTanggalBergabung) {
                            $user = User::create([
                                'name'      => Str::upper($nama),
                                'username'  => $nik,
                                'no_hp'     => $hp ?: null,
                                'password'  => null,
                                'role'      => 'warga',
                                'is_active' => true,
                            ]);

                            Warga::create([
                                'nik'               => $nik,
                                'nama_warga'        => Str::upper($nama),
                                'alamat'            => $alamat ?: '-',
                                'no_hp'             => $hp ?: null,
                                'tanggal_bergabung' => $parsedTanggalBergabung,
                                'id_user'           => $user->id,
                                'status_keaktifan'  => 'aktif',
                            ]);
                        });

                        $inserted++;
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Baris {$lineNum}: Gagal disimpan untuk NIK '{$nik}', terjadi kesalahan: " . $e->getMessage();
                    continue;
                }
            }

            $this->writeLog(
                'import',
                "Import Excel warga. Baru: {$inserted}, diperbarui: {$updated}, dilewati: {$skipped}",
                $request
            );

            return ApiResponse::success(
                [
                    'inserted' => $inserted,
                    'updated'  => $updated,
                    'skipped'  => $skipped,
                    'errors'   => $errors,
                ],
                "Import selesai. {$inserted} data baru ditambahkan, {$updated} data diperbarui, {$skipped} dilewati."
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('Terjadi kesalahan saat import.', $e->getMessage(), 500);
        }
    }
    public function exportTemplate(): BinaryFileResponse
    {
        $headers = ['NIK', 'Nama Warga', 'Alamat', 'No HP', 'Tanggal Bergabung (YYYY-MM-DD)'];

        $contohData = [
            ['3171234567890001', 'Budi Santoso', 'Jl. Merdeka No. 1', '081234567890', '2020-01-15'],
            ['3171234567890002', 'Siti Aminah', 'Jl. Sudirman No. 5', '082345678901', '2021-06-01'],
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $colIndex => $header) {
            $col = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue("{$col}1", $header);
            $sheet->getStyle("{$col}1")->getFont()->setBold(true);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        foreach ($contohData as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $col = Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->setCellValueExplicit(
                    "{$col}" . ($rowIndex + 2),
                    $value,
                    DataType::TYPE_STRING
                );
            }
        }

        $filename = 'template_import_warga.xlsx';
        $tempPath = storage_path("app/temp/{$filename}");

        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function writeLog(string $action, string $description, Request $request): void
    {
        try {
            ActivityLog::create([
                'id_user'            => Auth::user()?->id,
                'nama_user_snapshot' => Auth::user()?->name,
                'action'             => $action,
                'description'        => $description,
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Gagal menulis activity log: {$e->getMessage()}");
        }
    }
}
