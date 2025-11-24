<?php

namespace App\Http\Controllers;

use App\Models\InformasiIuran;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class InformasiIuranController extends Controller
{
    public function index(Request $request)
    {
        $query = InformasiIuran::select(
            'id',
            'jenis_iuran',
            'periode',
            'jumlah_iuran',
            'status_aktif'
        );

        $sortBy  = $request->query('sort_by');
        $sortDir = $request->query('sort_dir', 'asc');

        if ($sortBy) {
            $validColumns = \Schema::getColumnListing('informasi_iuran');

            if (in_array($sortBy, $validColumns)) {
                $query->orderBy($sortBy, strtolower($sortDir) === 'desc' ? 'desc' : 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }
        }

        $mode = $request->query('mode', 'client');

        if ($mode === 'admin') {
            $data = $query->paginate(
                $request->get('per_page', 10)
            );

            return ApiResponse::success(
                $data,
                'Data informasi iuran berhasil diambil.'
            );
        }

        $data = $query->get();

        return ApiResponse::success(
            $data,
            'Data informasi iuran berhasil diambil.'
        );
    }

    public function show($id)
    {
        $iuran = InformasiIuran::find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($iuran, 'Detail informasi iuran berhasil diambil.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'jenis_iuran' => 'required|in:bulanan,kematian',
            'periode' => 'nullable|integer|regex:/^[0-9]{4}$/|min:1900|max:2100',
            'jumlah_iuran' => 'required|numeric|min:0',
            'keterangan' => 'nullable|string',
            'status_aktif' => 'boolean'
        ], [
            'jenis_iuran.required' => 'Jenis iuran wajib diisi.',
            'jenis_iuran.in' => 'Jenis iuran hanya boleh berisi bulanan atau kematian.',

            'periode.regex' => 'Periode harus berupa tahun 4 digit, misalnya 2025.',
            'periode.integer' => 'Periode harus berupa angka.',
            'periode.min' => 'Tahun periode minimal 1900.',
            'periode.max' => 'Tahun periode maksimal 2100.',

            'jumlah_iuran.required' => 'Jumlah iuran wajib diisi.',
            'jumlah_iuran.numeric' => 'Jumlah iuran harus berupa angka.',
            'jumlah_iuran.min' => 'Jumlah iuran minimal bernilai 0.',

            'keterangan.string' => 'Keterangan harus berupa teks.',

            'status_aktif.boolean' => 'Status aktif harus bernilai true atau false.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        if (isset($data['status_aktif']) && $data['status_aktif'] == false) {
            $data['tanggal_nonaktif'] = now();
        } else {
            $data['tanggal_nonaktif'] = null;
        }

        if ($data['jenis_iuran'] === 'bulanan') {

            if (!$data['periode']) {
                return ApiResponse::error('Periode wajib diisi untuk iuran bulanan.', null, 422);
            }

            $cek = InformasiIuran::where('jenis_iuran', 'bulanan')
                ->where('periode', $data['periode'])
                ->where('status_aktif', true)
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Iuran bulanan untuk tahun {$data['periode']} sudah ada dan masih aktif.",
                    null,
                    409
                );
            }
        }

        if ($data['jenis_iuran'] === 'kematian') {

            $data['periode'] = null;

            $cek = InformasiIuran::where('jenis_iuran', 'kematian')
                ->where('status_aktif', true)
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Sudah ada informasi iuran kematian yang aktif.",
                    null,
                    409
                );
            }
        }

        $iuran = InformasiIuran::create($data);

        return ApiResponse::success($iuran, 'Informasi iuran berhasil ditambahkan.', 201);
    }

    public function update(Request $request, $id)
    {
        $iuran = InformasiIuran::find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'jenis_iuran' => 'sometimes|in:bulanan,kematian',
            'periode' => 'nullable|integer|regex:/^[0-9]{4}$/|min:1900|max:2100',
            'jumlah_iuran' => 'sometimes|required|numeric|min:0',
            'keterangan' => 'nullable|string',
            'status_aktif' => 'boolean'
        ], [
            'jenis_iuran.in' => 'Jenis iuran hanya boleh berisi bulanan atau kematian.',

            'periode.regex' => 'Periode harus berupa tahun 4 digit, misalnya 2025.',
            'periode.integer' => 'Periode harus berupa angka.',
            'periode.min' => 'Tahun periode minimal 1900.',
            'periode.max' => 'Tahun periode maksimal 2100.',

            'jumlah_iuran.required' => 'Jumlah iuran wajib diisi.',
            'jumlah_iuran.numeric' => 'Jumlah iuran harus berupa angka.',
            'jumlah_iuran.min' => 'Jumlah iuran minimal bernilai 0.',

            'keterangan.string' => 'Keterangan harus berupa teks.',

            'status_aktif.boolean' => 'Status aktif harus bernilai true atau false.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        if (isset($data['status_aktif']) && $data['status_aktif'] == false) {
            $data['tanggal_nonaktif'] = now();
        } else {
            $data['tanggal_nonaktif'] = null;
        }

        $jenis = $data['jenis_iuran'] ?? $iuran->jenis_iuran;

        if ($jenis === 'bulanan') {

            $periode = $data['periode'] ?? $iuran->periode;
            if (!$periode) {
                return ApiResponse::error('Periode wajib diisi untuk iuran bulanan.', null, 422);
            }

            $cek = InformasiIuran::where('jenis_iuran', 'bulanan')
                ->where('periode', $periode)
                ->where('status_aktif', true)
                ->where('id', '!=', $iuran->id)
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Iuran bulanan untuk tahun {$periode} sudah ada dan masih aktif.",
                    null,
                    409
                );
            }

            $data['periode'] = $periode;
        }

        if ($jenis === 'kematian') {

            $data['periode'] = null;

            $cek = InformasiIuran::where('jenis_iuran', 'kematian')
                ->where('status_aktif', true)
                ->where('id', '!=', $iuran->id)
                ->first();

            if ($cek) {
                return ApiResponse::error(
                    "Sudah ada informasi iuran kematian yang aktif.",
                    null,
                    409
                );
            }
        }

        $iuran->update($data);

        return ApiResponse::success($iuran, 'Informasi iuran berhasil diperbarui.');
    }


    public function destroy($id)
    {
        $iuran = InformasiIuran::find($id);

        if (!$iuran) {
            return ApiResponse::error('Informasi iuran tidak ditemukan.', null, 404);
        }

        $iuran->status_aktif = false;
        $iuran->tanggal_nonaktif = now();
        $iuran->save();

        return ApiResponse::success(
            null, 
            'Informasi iuran berhasil dinonaktifkan. Data akan dihapus permanen setelah 30 hari.'
        );
    }

}
