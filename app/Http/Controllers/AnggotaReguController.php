<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AnggotaRegu;
use App\Models\Regu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AnggotaReguController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = AnggotaRegu::with([
            'warga:nik,nama_warga',
            'regu:id,nama_regu',
        ]);

        if ($user->role === 'ketua_regu') {
            $regu = Regu::where('id_user', $user->id)->first();

            if ($regu) {
                $query->where('id_regu', $regu->id);
            } else {
                return ApiResponse::success(
                    ['data' => [], 'leader_available' => false],
                    'User belum memiliki regu.'
                );
            }
        }

        if ($request->filled('id_regu')) {
            $query->where('id_regu', $request->id_regu);
        }

        $hasLeader = (clone $query)->where('is_leader', true)->exists();

        $query->orderByDesc('is_leader');

        return ApiResponse::success(
            ['data' => $query->get(), 'leader_available' => $hasLeader],
            'Data anggota regu berhasil diambil.'
        );
    }

    public function show($id)
    {
        $anggota = AnggotaRegu::with(['warga', 'regu'])->find($id);

        if (!$anggota) {
            return ApiResponse::error('Data anggota regu tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($anggota, 'Detail anggota regu berhasil diambil.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_regu'  => 'required|exists:regu,id',
            'niks'     => 'required|array|min:1',
            'niks.*'   => 'required|exists:warga,nik|distinct',
        ], [
            'id_regu.required'   => 'ID regu wajib diisi.',
            'id_regu.exists'     => 'Regu tidak ditemukan.',
            'niks.required'      => 'Warga wajib dipilih.',
            'niks.array'         => 'Format data warga tidak valid.',
            'niks.min'           => 'Minimal pilih satu warga.',
            'niks.*.exists'      => 'Terdapat data warga yang tidak valid.',
            'niks.*.distinct'    => 'Terdapat NIK duplikat.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                collect($validator->errors()->all())->first(),
                422
            );
        }

        $niks   = $validator->validated()['niks'];
        $idRegu = $validator->validated()['id_regu'];
        $regu   = Regu::find($idRegu);

        $existingNik = AnggotaRegu::whereIn('nik', $niks)
            ->whereNull('deleted_at')
            ->pluck('nik')
            ->toArray();

        if (!empty($existingNik)) {
            return ApiResponse::error(
                'Validasi gagal.',
                'Beberapa warga sudah terdaftar sebagai anggota regu.',
                422,
                ['nik_sudah_terdaftar' => $existingNik]
            );
        }

        $reguHasLeader = AnggotaRegu::where('id_regu', $idRegu)
            ->where('is_leader', true)
            ->whereNull('deleted_at')
            ->exists();

        $insertData = [];

        foreach ($niks as $nik) {
            $insertData[] = [
                'id_regu'          => $idRegu,
                'nik'              => $nik,
                'status_keaktifan' => 'aktif',
                'is_leader'        => false,
            ];
        }

        DB::table('anggota_regu')->insert(array_map(function ($user) {
            return array_merge($user, [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, $insertData));

        $message = 'Anggota regu berhasil ditambahkan.';

        if (!$reguHasLeader) {
            $message .= ' Regu belum memiliki ketua.';
        }

        $this->writeLog(
            'create',
            'Menambahkan ' . count($niks) . ' anggota ke regu "' . $regu->nama_regu . '".',
            $request
        );

        return ApiResponse::success(null, $message, 201);
    }

    public function destroy(Request $request, $id)
    {
        $anggota = AnggotaRegu::find($id);

        if (!$anggota) {
            return ApiResponse::error('Data anggota regu tidak ditemukan.', null, 404);
        }

        $isLeader = $anggota->is_leader;

        $anggota->delete();

        $message = 'Anggota regu berhasil dihapus.';

        if ($isLeader) {
            $message .= ' Anggota yang dihapus merupakan ketua, silakan tunjuk ketua baru jika diperlukan.';
        }

        $this->writeLog(
            'delete',
            'Menghapus anggota regu dengan NIK ' . $anggota->nik . ' dari regu ID ' . $anggota->id_regu . '.',
            $request
        );

        return ApiResponse::success(null, $message);
    }

    public function setLeader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_regu' => 'required|exists:regu,id',
            'nik'     => 'required|exists:anggota_regu,nik',
        ], [
            'id_regu.required' => 'ID regu wajib diisi.',
            'id_regu.exists'   => 'Regu tidak ditemukan.',
            'nik.required'     => 'NIK wajib diisi.',
            'nik.exists'       => 'Anggota regu tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                collect($validator->errors()->all())->first(),
                422
            );
        }

        $idRegu  = $request->id_regu;
        $nikBaru = $request->nik;

        $anggotaBaru = AnggotaRegu::where('id_regu', $idRegu)
            ->where('nik', $nikBaru)
            ->whereNull('deleted_at')
            ->first();

        if (!$anggotaBaru) {
            return ApiResponse::error('Data tidak valid.', 'Anggota tidak terdaftar atau sudah di-reset.', 404);
        }

        $regu = Regu::find($idRegu);

        DB::transaction(function () use ($idRegu, $anggotaBaru) {
            AnggotaRegu::where('id_regu', $idRegu)
                ->where('is_leader', true)
                ->whereNull('deleted_at')
                ->update(['is_leader' => false]);

            $anggotaBaru->update(['is_leader' => true]);
        });

        $this->writeLog(
            'update',
            'Mengubah ketua regu pada regu "' . $regu->nama_regu . '" menjadi warga dengan NIK ' . $nikBaru . '.',
            $request
        );

        return ApiResponse::success(null, 'Ketua regu berhasil diperbarui.');
    }

    public function resetAnggota(Request $request, $id)
    {
        $anggota = AnggotaRegu::find($id);

        if (!$anggota) {
            return ApiResponse::error('Data tidak ditemukan.', 'Anggota regu tidak ditemukan.', 404);
        }

        $anggota->status_keaktifan = 'tidak_aktif';
        $anggota->save();
        $anggota->delete();

        $this->writeLog(
            'reset',
            'Mereset anggota regu dengan NIK ' . $anggota->nik . '.',
            $request
        );

        return ApiResponse::success(null, 'Anggota regu berhasil di-reset.');
    }

    public function resetAnggotaByRegu(Request $request, $idRegu)
    {
        $query       = AnggotaRegu::where('id_regu', $idRegu);
        $anggotaList = $query->get();

        if ($anggotaList->isEmpty()) {
            return ApiResponse::error('Data kosong.', 'Tidak ada anggota pada regu ini.', 404);
        }

        $count = $anggotaList->count();

        foreach ($anggotaList as $anggota) {
            $anggota->status_keaktifan = 'tidak_aktif';
            $anggota->save();
        }

        $query->delete();

        $regu = Regu::find($idRegu);

        $this->writeLog(
            'reset',
            'Mereset seluruh anggota pada regu "' . ($regu->nama_regu ?? '-') . '" sebanyak ' . $count . ' warga.',
            $request
        );

        return ApiResponse::success(['total_reset' => $count], 'Semua anggota pada regu berhasil di-reset.');
    }

    public function resetAllAnggota(Request $request)
    {
        $count = AnggotaRegu::count();

        if ($count === 0) {
            return ApiResponse::error('Data kosong.', 'Tidak ada anggota regu untuk di-reset.', 404);
        }

        AnggotaRegu::query()->update(['status_keaktifan' => 'tidak_aktif']);
        AnggotaRegu::query()->delete();

        $this->writeLog(
            'reset',
            'Mereset seluruh anggota dari semua regu sebanyak ' . $count . ' data.',
            $request
        );

        return ApiResponse::success(['total_reset' => $count], 'Semua anggota dari seluruh regu berhasil di-reset.');
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
