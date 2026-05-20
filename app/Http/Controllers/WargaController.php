<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
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
            'nik'        => 'required|string|max:32|unique:warga,nik',
            'nama_warga' => 'required|string|max:100',
            'alamat'     => 'required|string',
            'no_hp'      => 'required|string|max:20',
        ], [
            'nik.required'        => 'NIK wajib diisi.',
            'nik.string'          => 'NIK harus berupa teks.',
            'nik.max'             => 'NIK maksimal 32 karakter.',
            'nik.unique'          => 'NIK sudah terdaftar.',
            'nama_warga.required' => 'Nama warga wajib diisi.',
            'nama_warga.string'   => 'Nama warga harus berupa teks.',
            'nama_warga.max'      => 'Nama warga maksimal 100 karakter.',
            'alamat.required'     => 'Alamat wajib diisi.',
            'alamat.string'       => 'Alamat harus berupa teks.',
            'no_hp.required'      => 'Nomor HP wajib diisi.',
            'no_hp.string'        => 'Nomor HP harus berupa teks.',
            'no_hp.max'           => 'Nomor HP maksimal 20 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            Warga::create([
                ...$validator->validated(),
                'id_user'          => null,
                'status_keaktifan' => 'aktif',
            ]);

            $this->writeLog('create', "Menambahkan data warga baru dengan NIK {$request->nik}", $request);

            DB::commit();

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

        dd($request);

        $validator = Validator::make($request->all(), [
            'nama_warga' => 'sometimes|required|string|max:100',
            'alamat'     => 'sometimes|required|string',
            'no_hp'      => 'nullable|string|max:20',
        ], [
            'nama_warga.required' => 'Nama warga wajib diisi.',
            'nama_warga.max'      => 'Nama warga maksimal 100 karakter.',
            'alamat.required'     => 'Alamat wajib diisi.',
            'no_hp.max'           => 'Nomor HP maksimal 20 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga->update($validator->validated());

        $this->writeLog('update', "Memperbarui data warga NIK {$warga->nik}", $request);

        return ApiResponse::success(null, 'Data warga berhasil diperbarui.');
    }

    public function destroy(Request $request, string $nik): JsonResponse
    {
        $warga = Warga::find($nik);

        if (!$warga) {
            return ApiResponse::error('Data warga tidak ditemukan.', null, 404);
        }

        try {
            $warga->status_keaktifan = 'tidak_aktif';
            $warga->save();

            $warga->delete();

            $this->writeLog('delete', "Soft delete warga NIK {$warga->nik} ({$warga->nama_warga})", $request);

            return ApiResponse::success(
                null,
                'Data warga berhasil dinonaktifkan.'
            );
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Tidak dapat menghapus warga.',
                collect($e->errors())->flatten()->first(),
                422
            );
        } catch (\Throwable $e) {
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
        $warga->save();

        $this->writeLog(
            'update_status',
            "Mengubah status warga NIK {$warga->nik} ({$warga->nama_warga}) menjadi {$statusBaru}",
            $request
        );

        return ApiResponse::success(null, 'Status keaktifan berhasil diperbarui.');
    }

    public function importExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:5120',
        ]);

        DB::beginTransaction();

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
            $skipped  = 0;
            $errors   = [];

            foreach ($data as $rowIndex => $row) {
                $lineNum = $rowIndex + 2;

                $nik    = trim((string) ($row[0] ?? ''));
                $nama   = trim((string) ($row[1] ?? ''));
                $alamat = trim((string) ($row[2] ?? ''));
                $hp     = trim((string) ($row[3] ?? ''));

                if (!$nik || !$nama) {
                    $skipped++;
                    $errors[] = "Baris {$lineNum}: NIK dan Nama wajib diisi.";
                    continue;
                }

                if (strlen($nik) > 32) {
                    $skipped++;
                    $errors[] = "Baris {$lineNum}: NIK '{$nik}' melebihi 32 karakter.";
                    continue;
                }

                if (Warga::withTrashed()->where('nik', $nik)->exists()) {
                    $skipped++;
                    $errors[] = "Baris {$lineNum}: NIK '{$nik}' sudah terdaftar, dilewati.";
                    continue;
                }

                Warga::create([
                    'nik'              => $nik,
                    'nama_warga'       => $nama,
                    'alamat'           => $alamat ?: '-',
                    'no_hp'            => $hp ?: null,
                    'id_user'          => null,
                    'status_keaktifan' => 'aktif',
                ]);

                $inserted++;
            }

            $this->writeLog(
                'import',
                "Import Excel warga. Berhasil: {$inserted}, dilewati: {$skipped}",
                $request
            );

            DB::commit();

            return ApiResponse::success(
                [
                    'inserted' => $inserted,
                    'skipped'  => $skipped,
                    'errors'   => $errors,
                ],
                "Import selesai. {$inserted} data berhasil ditambahkan, {$skipped} dilewati."
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat import.', $e->getMessage(), 500);
        }
    }

    public function exportTemplate(): BinaryFileResponse
    {
        $headers = ['NIK', 'Nama Warga', 'Alamat', 'No HP'];

        $contohData = [
            ['3171234567890001', 'Budi Santoso', 'Jl. Merdeka No. 1', '081234567890'],
            ['3171234567890002', 'Siti Aminah', 'Jl. Sudirman No. 5', '082345678901'],
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
                'id_user'            => Auth::id(),
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
