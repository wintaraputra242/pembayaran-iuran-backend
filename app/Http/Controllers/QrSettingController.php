<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\QrSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Str;

class QrSettingController extends Controller
{
    public function index()
    {
        $qris = QrSetting::with('uploadedBy')->orderBy('is_active', 'desc')->latest()->get();

        return ApiResponse::success(
            $qris->map(fn($item) => [
                'id'             => $item->id,
                'image'          => asset('storage/' . $item->image),
                'nama_rekening'  => $item->nama_rekening,
                'nomor_rekening' => $item->nomor_rekening,
                'keterangan'     => $item->keterangan,
                'is_active'      => $item->is_active,
                'uploaded_by'    => $item->uploadedBy?->name,
                'created_at'     => $item->created_at?->toDateTimeString(),
            ]),
            'success'
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'image'          => 'required|image|mimes:jpg,jpeg,png,webp|max:5000',
            'nama_rekening'  => 'nullable|string|max:255',
            'nomor_rekening' => 'nullable|string|max:255',
            'keterangan'     => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            QrSetting::where('is_active', true)->update(['is_active' => false]);

            $file      = $request->file('image');
            $filename  = 'qris-' . time() . '-' . Str::random(6) . '.jpg';
            $manager   = new ImageManager(new Driver());
            $image     = $manager->read($file)->toJpeg(80);
            Storage::disk('public')->put('qris/' . $filename, (string) $image);
            $imagePath = 'qris/' . $filename;

            $qris = QrSetting::create([
                'image'          => $imagePath,
                'nama_rekening'  => $request->nama_rekening,
                'nomor_rekening' => $request->nomor_rekening,
                'keterangan'     => $request->keterangan,
                'is_active'      => true,
                'uploaded_by'    => Auth::user()->id,
            ]);

            DB::commit();

            return ApiResponse::success([
                'id'             => $qris->id,
                'image'          => asset('storage/' . $qris->image),
                'nama_rekening'  => $qris->nama_rekening,
                'nomor_rekening' => $qris->nomor_rekening,
                'keterangan'     => $qris->keterangan,
                'is_active'      => $qris->is_active,
                'uploaded_by'    => Auth::user()->name,
            ], 'QRIS berhasil ditambahkan.');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error($e, null, 500);
        }
    }


    public function setActive(int $id)
    {
        DB::beginTransaction();

        try {
            $qris = QrSetting::find($id);

            if (!$qris) {
                return ApiResponse::error('QRIS tidak ditemukan.', null, 404);
            }

            QrSetting::where('is_active', true)->update(['is_active' => false]);
            $qris->update(['is_active' => true]);

            DB::commit();

            return ApiResponse::success(null, 'QRIS aktif berhasil diubah.');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Gagal mengubah QRIS aktif.', null, 500);
        }
    }

    public function destroy(int $id)
    {
        DB::beginTransaction();

        try {
            $qris = QrSetting::find($id);

            if (!$qris) {
                return ApiResponse::error('QRIS tidak ditemukan.', null, 404);
            }

            if ($qris->is_active) {
                return ApiResponse::error('QRIS yang sedang aktif tidak bisa dihapus.', null, 422);
            }

            Storage::disk('public')->delete($qris->image);
            $qris->delete();

            DB::commit();

            return ApiResponse::success(null, 'QRIS berhasil dihapus.');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Gagal menghapus QRIS.', null, 500);
        }
    }

    public function active()
    {
        $qris = QrSetting::where('is_active', true)->latest()->first();

        if (!$qris) {
            return ApiResponse::error('QRIS tidak tersedia saat ini.', null, 404);
        }

        return ApiResponse::success([
            'id'             => $qris->id,
            'image'          => asset('storage/' . $qris->image),
            'nama_rekening'  => $qris->nama_rekening,
            'nomor_rekening' => $qris->nomor_rekening,
            'keterangan'     => $qris->keterangan,
        ], 'success');
    }

    public function download(int $id)
    {
        $qris = QrSetting::findOrFail($id);

        $path = Storage::disk('public')->path($qris->image);

        return response()->download($path, 'qris.png', [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="qris.png"',
        ]);
    }
}
