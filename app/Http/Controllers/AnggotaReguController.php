<?php

namespace App\Http\Controllers;

use App\Models\AnggotaRegu;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;

class AnggotaReguController extends Controller
{
    public function index(Request $request)
    {
        $query = AnggotaRegu::with([
            'warga:nik,nama_warga', 
            'regu:id,nama_regu'         
        ]);

        if ($request->filled('id_regu')) {
            $query->where('id_regu', $request->id_regu);
        }

        return ApiResponse::success(
            $query->get(),
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
            'nik' => 'required|exists:warga,nik|unique:anggota_regu,nik',
            'status_keaktifan' => 'required|in:aktif,tidak_aktif',
            'is_leader' => 'required|boolean'
        ], [
            'id_regu.required' => 'ID regu wajib diisi.',
            'id_regu.exists' => 'Regu tidak ditemukan.',

            'nik.required' => 'NIK wajib diisi.',
            'nik.exists' => 'Data warga tidak ditemukan.',
            'nik.unique' => 'Warga ini sudah menjadi anggota regu manapun.',

            'status_keaktifan.required' => 'Status keaktifan wajib diisi.',
            'status_keaktifan.in' => 'Status hanya boleh aktif atau tidak_aktif.',

            'is_leader.required' => 'Status leader wajib diisi.',
            'is_leader.boolean' => 'Status leader harus bernilai true atau false.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                collect($validator->errors()->all())->first(),
                422
            );
        }

        $data = $validator->validated();

        // Cek apakah regu sudah punya leader
        $existingLeader = AnggotaRegu::where('id_regu', $data['id_regu'])
            ->where('is_leader', true)
            ->first();

        $blockedLeaderMessage = null;

        // Jika sudah ada leader, anggota baru tidak boleh menjadi leader
        if ($existingLeader) {
            if ($data['is_leader'] == true) {
                $data['is_leader'] = false;
                $blockedLeaderMessage = "Anggota berhasil ditambahkan, tetapi tidak dapat menjadi ketua karena regu sudah memiliki ketua.";
            }
        }

        // Simpan data anggota
        $anggota = AnggotaRegu::create($data);

        $message = 'Anggota regu berhasil ditambahkan.';
        if ($blockedLeaderMessage) {
            $message .= ' ' . $blockedLeaderMessage;
        }

        return ApiResponse::success($anggota, $message, 201);
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

        return ApiResponse::success(null, $message, 200);
    }


    public function updateLeader(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'id_regu' => 'required|exists:regu,id',
        ], [
            'id_regu.required' => 'ID regu wajib diisi.',
            'id_regu.exists' => 'Data regu tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                collect($validator->errors()->all())->first(),
                422
            );
        }

        $anggota = AnggotaRegu::find($id);

        if (!$anggota) {
            return ApiResponse::error('Data anggota regu tidak ditemukan.', null, 404);
        }

        if ($anggota->id_regu != $request->id_regu) {
            return ApiResponse::error('Anggota ini tidak termasuk dalam regu tersebut.', null, 400);
        }

        AnggotaRegu::where('id_regu', $request->id_regu)
            ->update(['is_leader' => false]);

        $anggota->update(['is_leader' => true]);

        return ApiResponse::success(
            $anggota,
            'Ketua regu berhasil diperbarui.'
        );
    }
}
