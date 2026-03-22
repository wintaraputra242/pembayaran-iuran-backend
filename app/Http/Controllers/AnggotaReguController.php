<?php

namespace App\Http\Controllers;

use App\Models\AnggotaRegu;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\ActivityLog;
use App\Models\Regu;
use Illuminate\Support\Facades\Auth;

class AnggotaReguController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = AnggotaRegu::with([
            'warga:nik,nama_warga',
            'regu:id,nama_regu'
        ]);

        // Jika role ketua_regu → ambil regu berdasarkan id_user
        if ($user->role === 'ketua_regu') {
            $regu = Regu::where('id_user', $user->id)->first();

            if ($regu) {
                $query->where('id_regu', $regu->id);
            } else {
                return ApiResponse::success(
                    [
                        'data' => [],
                        'leader_available' => false
                    ],
                    'User belum memiliki regu.'
                );
            }
        }

        // Optional filter dari request (admin use case)
        if ($request->filled('id_regu')) {
            $query->where('id_regu', $request->id_regu);
        }

        $hasLeader = (clone $query)
            ->where('is_leader', true)
            ->exists();

        $query->orderBy('is_leader', 'desc');

        return ApiResponse::success(
            [
                'data' => $query->get(),
                'leader_available' => $hasLeader
            ],
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
            'id_regu' => 'required|exists:regu,id',
            'niks' => 'required|array|min:1',
            'niks.*' => 'required|exists:warga,nik|distinct',
        ], [
            'id_regu.required' => 'ID regu wajib diisi.',
            'id_regu.exists' => 'Regu tidak ditemukan.',

            'niks.required' => 'Warga wajib dipilih.',
            'niks.array' => 'Format data warga tidak valid.',
            'niks.min' => 'Minimal pilih satu warga.',
            'niks.*.exists' => 'Terdapat data warga yang tidak valid.',
            'niks.*.distinct' => 'Terdapat NIK duplikat.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                collect($validator->errors()->all())->first(),
                422
            );
        }

        $niks = $validator->validated()['niks'];
        $idRegu = $validator->validated()['id_regu'];

        // Ambil nama regu
        $regu = Regu::find($idRegu);

        // Cek NIK yang sudah terdaftar sebagai anggota regu manapun
        $existingNik = AnggotaRegu::whereIn('nik', $niks)
            ->pluck('nik')
            ->toArray();

        if (!empty($existingNik)) {
            return ApiResponse::error(
                'Validasi gagal.',
                'Beberapa warga sudah terdaftar sebagai anggota regu.',
                422,
                [
                    'nik_sudah_terdaftar' => $existingNik
                ]
            );
        }

        // Cek apakah regu sudah punya leader
        $reguHasLeader = AnggotaRegu::where('id_regu', $idRegu)
            ->where('is_leader', true)
            ->exists();

        $insertData = [];

        foreach ($niks as $nik) {
            $insertData[] = [
                'id_regu' => $idRegu,
                'nik' => $nik,
                'status_keaktifan' => 'aktif', 
                'is_leader' => false,          
            ];
        }

        AnggotaRegu::insert($insertData);

        $message = 'Anggota regu berhasil ditambahkan.';

        if (!$reguHasLeader) {
            $message .= ' Regu belum memiliki ketua.';
        }

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'create',
            'description' => 'Menambahkan ' . count($niks) . ' anggota ke regu "' . $regu->nama_regu . '".',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            $message,
            201
        );
    }


    public function update(Request $request, $nik)
    {
        //
    }

    public function destroy($id)
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

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'delete',
            'description' => 'Menghapus anggota regu dengan NIK ' . $anggota->nik . ' dari regu ID ' . $anggota->id_regu . '.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(null, $message, 200);
    }


    public function setLeader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_regu' => 'required|exists:regu,id',
            'nik' => 'required|exists:anggota_regu,nik',
        ], [
            'id_regu.required' => 'ID regu wajib diisi.',
            'id_regu.exists' => 'Regu tidak ditemukan.',

            'nik.required' => 'NIK wajib diisi.',
            'nik.exists' => 'Anggota regu tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                collect($validator->errors()->all())->first(),
                422
            );
        }

        $idRegu = $request->id_regu;
        $nikBaru = $request->nik;

        // Pastikan anggota ini memang milik regu tsb & masih aktif
        $anggotaBaru = AnggotaRegu::where('id_regu', $idRegu)
            ->where('nik', $nikBaru)
            ->whereNull('deleted_at')
            ->first();

        if (!$anggotaBaru) {
            return ApiResponse::error(
                'Data tidak valid.',
                'Anggota tidak terdaftar atau sudah di-reset.',
                404
            );
        }

        $regu = Regu::find($idRegu); // ambil nama regu

        DB::transaction(function () use ($idRegu, $anggotaBaru) {

            // 1️⃣ Turunkan leader lama (jika ada)
            AnggotaRegu::where('id_regu', $idRegu)
                ->where('is_leader', true)
                ->whereNull('deleted_at')
                ->update(['is_leader' => false]);

            // 2️⃣ Set leader baru
            $anggotaBaru->update([
                'is_leader' => true
            ]);
        });

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'update',
            'description' => 'Mengubah ketua regu pada regu "' . $regu->nama_regu . '" menjadi warga dengan NIK ' . $nikBaru . '.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Ketua regu berhasil diperbarui.',
            200
        );
    }

    public function resetAnggota($id)
    {
        $anggota = AnggotaRegu::find($id);

        if (!$anggota) {
            return ApiResponse::error(
                'Data tidak ditemukan.',
                'Anggota regu tidak ditemukan.',
                404
            );
        }

        $anggota->delete();

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'reset',
            'description' => 'Mereset anggota regu dengan NIK ' . $anggota->nik . '.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Anggota regu berhasil di-reset.',
            200
        );
    }

    public function resetAnggotaByRegu($idRegu)
    {
        $count = AnggotaRegu::where('id_regu', $idRegu)->count();

        if ($count === 0) {
            return ApiResponse::error(
                'Data kosong.',
                'Tidak ada anggota pada regu ini.',
                404
            );
        }

        AnggotaRegu::where('id_regu', $idRegu)->delete();

        $regu = Regu::find($idRegu); // ambil nama regu

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'reset',
            'description' => 'Mereset seluruh anggota pada regu "' . $regu->nama_regu . '" sebanyak ' . $count . ' warga.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            ['total_reset' => $count],
            'Semua anggota pada regu berhasil di-reset.',
            200
        );
    }

    public function resetAllAnggota()
    {
        $count = AnggotaRegu::count();

        if ($count === 0) {
            return ApiResponse::error(
                'Data kosong.',
                'Tidak ada anggota regu untuk di-reset.',
                404
            );
        }

        AnggotaRegu::query()->delete();

        ActivityLog::create([
            'id_user' => Auth::id(),
            'action' => 'reset',
            'description' => 'Mereset seluruh anggota dari semua regu.',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ApiResponse::success(
            null,
            'Semua anggota dari seluruh regu berhasil di-reset.',
            200
        );
    }

}
