<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\ActivityLog;
use App\Models\InformasiIuran;
use App\Models\Notification as NotificationModel;
use App\Models\Pembayaran;
use App\Models\Warga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Midtrans\Notification;
use Midtrans\Snap;

class PembayaranController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Pembayaran::with([
            'warga.anggotaRegu.regu',
            'informasiIuran',
            'diprosesoleh:id,name,role',
        ]);

        // Filter berdasarkan ID pembayaran spesifik (misalnya saat navigasi dari notifikasi)
        if ($request->filled('id')) {
            $query->where('id', $request->id);
        }

        if ($request->filled('nama_warga')) {
            $query->where(function ($q) use ($request) {
                $q->where('nama_warga_snapshot', 'like', '%' . $request->nama_warga . '%')
                    ->orWhereHas(
                        'warga',
                        fn($q2) =>
                        $q2->where('nama_warga', 'like', '%' . $request->nama_warga . '%')
                    );
            });
        }

        if ($request->filled('nik')) {
            $query->where('nik_snapshot', $request->nik);
        }

        if ($request->filled('regu')) {
            $query->whereHas(
                'warga.anggotaRegu.regu',
                fn($q) =>
                $q->where('id', $request->regu)
            );
        }

        if ($request->filled('jenis_iuran')) {
            $query->whereHas(
                'informasiIuran',
                fn($q) =>
                $q->where('jenis_iuran', $request->jenis_iuran)
            );
        }

        if ($request->filled('metode_bayar')) {
            $query->where('metode_bayar', $request->metode_bayar);
        }

        if ($request->filled('status_bayar')) {
            $query->where('status_bayar', $request->status_bayar);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('tanggal_bayar', [
                $request->start_date,
                $request->end_date,
            ]);
        }

        $perPage = $request->get('per_page', 10);

        $data = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::success($data, 'Data pembayaran berhasil diambil.');
    }

    public function show(int $nik): JsonResponse
    {
        $pembayaran = Pembayaran::with([
            'warga',
            'informasiIuran',
            'diprosesoleh:id,name,role',
        ])->where('nik', $nik)->first();

        if (!$pembayaran) {
            return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
        }

        return ApiResponse::success($pembayaran, 'Detail pembayaran berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik'                => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'tanggal_bayar'      => 'required|date',
            'total_bayar'        => 'required|numeric|min:0',
            'bulan'              => 'nullable|array',
            'bulan.*'            => 'integer|min:1|max:12',
            'metode_bayar'       => 'required|in:tunai,transfer,qris',
            'bukti_pembayaran'   => 'nullable|image|mimes:jpg,jpeg,png|max:5000',
            'note'               => 'nullable|string|max:500',
        ], [
            'nik.required'                => 'NIK wajib diisi.',
            'nik.exists'                  => 'NIK tidak ditemukan dalam data warga.',
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran tidak valid.',
            'tanggal_bayar.required'      => 'Tanggal bayar wajib diisi.',
            'tanggal_bayar.date'          => 'Tanggal bayar harus berupa tanggal yang valid.',
            'total_bayar.required'        => 'Total bayar wajib diisi.',
            'total_bayar.numeric'         => 'Total bayar harus berupa angka.',
            'total_bayar.min'             => 'Total bayar minimal bernilai 0.',
            'bulan.array'                 => 'Bulan harus berupa array.',
            'bulan.*.integer'             => 'Nilai bulan harus berupa angka.',
            'bulan.*.min'                 => 'Bulan minimal 1.',
            'bulan.*.max'                 => 'Bulan maksimal 12.',
            'metode_bayar.required'       => 'Metode pembayaran wajib diisi.',
            'metode_bayar.in'             => 'Metode bayar harus salah satu dari: tunai, transfer, atau qris.',
            'bukti_pembayaran.image'      => 'Bukti pembayaran harus berupa gambar.',
            'bukti_pembayaran.mimes'      => 'Bukti pembayaran harus berformat jpg, jpeg, atau png.',
            'bukti_pembayaran.max'        => 'Ukuran bukti pembayaran maksimal 5MB.',
            'note.max'                    => 'Catatan maksimal 500 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data  = $validator->validated();
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        if ($iuran->jenis_iuran === 'kematian') {
            $data['bulan'] = null;
        }

        $existingQuery = Pembayaran::where('nik', $data['nik'])
            ->where('id_informasi_iuran', $data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && !empty($data['bulan'])) {
            $existingQuery->where(function ($q) use ($data) {
                foreach ($data['bulan'] as $bulan) {
                    $q->orWhereJsonContains('bulan', (int) $bulan);
                }
            });
        }

        $existingPayment = $existingQuery->orderByDesc('created_at')->first();

        if ($existingPayment) {
            if ($existingPayment->status_bayar === 'approved') {
                return ApiResponse::error('Iuran sudah dibayar.', null, 409);
            }

            if ($existingPayment->status_bayar === 'pending') {
                return ApiResponse::success([
                    'pembayaran' => $existingPayment,
                ], 'Terdapat pembayaran yang masih menunggu validasi.');
            }
        }

        $warga = Warga::find($data['nik']);

        DB::beginTransaction();

        try {
            $pathBukti = null;

            if ($request->hasFile('bukti_pembayaran')) {
                $file     = $request->file('bukti_pembayaran');
                $filename = 'bukti-' . time() . '-' . Str::random(6) . '.jpg';

                $manager = new ImageManager(new Driver());
                $image   = $manager->read($file)->toJpeg(75);

                Storage::disk('public')->put('bukti-pembayaran/' . $filename, (string) $image);

                $pathBukti = 'bukti-pembayaran/' . $filename;
            }

            $pembayaran = Pembayaran::create([
                'nik'                   => $data['nik'],
                'id_informasi_iuran'    => $data['id_informasi_iuran'],
                'nik_snapshot'          => $warga->nik,
                'nama_warga_snapshot'   => $warga->nama_warga,
                'jumlah_iuran_snapshot' => $iuran->jumlah_iuran ?? 0,
                'bulan'                 => $data['bulan'] ?? null,
                'tanggal_bayar'         => $data['tanggal_bayar'],
                'total_bayar'           => $data['total_bayar'],
                'metode_bayar'          => $data['metode_bayar'],
                'status_bayar'          => 'approved', // langsung approved karena diinput pengurus
                'submitted_at'          => now(),
                'processed_by'          => Auth::user()->id,
                'validated_by'          => Auth::user()->id,
                'validated_at'          => now(),
                'bukti_pembayaran'      => $pathBukti,
                'note'                  => $data['note'] ?? null,
            ]);

            $this->writeLog(
                'create',
                "Membuat transaksi pembayaran ID {$pembayaran->id} untuk warga {$warga->nama_warga} (NIK: {$warga->nik}).",
                $request
            );

            DB::commit();

            $warga->load('user.devices');

            $this->notifyWarga(
                $warga,
                'Pembayaran Dicatat ✅',
                "Pembayaran Anda untuk {$iuran->judul_iuran} telah dicatat dan disetujui oleh pengurus.",
                'approved',
                ['pembayaran_id' => $pembayaran->id, 'nik' => $warga->nik]
            );

            return ApiResponse::success([
                'pembayaran' => $pembayaran,
            ], 'Transaksi pembayaran berhasil.', 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat menyimpan pembayaran.', $e->getMessage(), 500);
        }
    }

    public function wargaUnpaidPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'bulan'              => 'nullable|integer|min:1|max:12',
            'id_regu'            => 'nullable|integer|exists:regu,id',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'bulan.integer'               => 'Bulan harus berupa angka.',
            'bulan.min'                   => 'Pilihan bulan minimal adalah bulan ke-1 (Januari).',
            'bulan.max'                   => 'Pilihan bulan maksimal adalah bulan ke-12 (Desember).',
            'id_regu.integer'             => 'Regu harus berupa nilai yang valid.',
            'id_regu.exists'              => 'Regu yang dipilih tidak valid atau tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data  = $validator->validated();
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if (!$iuran) {
            return ApiResponse::error('Iuran tidak ditemukan.', null, 404);
        }

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        $user  = Auth::user();
        $bulan = $data['bulan'] ?? null;

        $query = Warga::with(['anggotaRegu.regu'])
            ->whereNull('deleted_at')
            ->whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
                $q->where('id_informasi_iuran', $data['id_informasi_iuran'])
                    ->whereIn('status_bayar', ['approved', 'pending']);

                if ($iuran->jenis_iuran === 'bulanan') {
                    $q->whereJsonContains('bulan', $data['bulan']);
                }
            })
            ->where(function ($q) use ($iuran, $bulan) {
                $q
                    ->where(function ($q1) use ($iuran, $bulan) {
                        $q1->where('status_keaktifan', 'aktif');

                        if ($iuran->jenis_iuran === 'bulanan' && $bulan) {
                            $tahunPeriode = (int) $iuran->periode;
                            $q1->where(function ($q2) use ($bulan, $tahunPeriode) {
                                $q2->whereYear('created_at', '<', $tahunPeriode)
                                    ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
                                        $q3->whereYear('created_at', $tahunPeriode)
                                            ->whereMonth('created_at', '<=', $bulan);
                                    });
                            });
                        }

                        if ($iuran->jenis_iuran === 'kematian') {
                            $q1->where('created_at', '<=', $iuran->created_at);
                        }
                    })
                    ->orWhere(function ($q1) use ($iuran, $bulan) {
                        $q1->where('status_keaktifan', 'tidak_aktif')
                            ->whereNotNull('tanggal_nonaktif');

                        if ($iuran->jenis_iuran === 'bulanan' && $bulan) {
                            $tahunPeriode = (int) $iuran->periode;

                            $q1->where(function ($q2) use ($bulan, $tahunPeriode) {
                                $q2->whereYear('tanggal_nonaktif', '>', $tahunPeriode)
                                    ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
                                        $q3->whereYear('tanggal_nonaktif', $tahunPeriode)
                                            ->whereMonth('tanggal_nonaktif', '>=', $bulan);
                                    });
                            });

                            $q1->where(function ($q2) use ($bulan, $tahunPeriode) {
                                $q2->whereYear('created_at', '<', $tahunPeriode)
                                    ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
                                        $q3->whereYear('created_at', $tahunPeriode)
                                            ->whereMonth('created_at', '<=', $bulan);
                                    });
                            });
                        }

                        if ($iuran->jenis_iuran === 'kematian') {
                            $q1->where('created_at', '<=', $iuran->created_at)
                                ->whereDate('tanggal_nonaktif', '>=', $iuran->created_at->toDateString());
                        }
                    });
            });

        if ($user->role === 'ketua_regu') {
            $query->whereHas(
                'anggotaRegu.regu',
                fn($q) => $q->where('id_user', $user->id)
            );
        }

        if ($request->filled('id_regu')) {
            $query->whereHas(
                'anggotaRegu.regu',
                fn($q) => $q->where('id', $request->id_regu)
            );
        }

        $perPage = $request->get('per_page', 10);
        $warga   = $query->orderBy('nama_warga')->paginate($perPage);

        return ApiResponse::success($warga, 'Daftar warga yang belum melakukan pembayaran berhasil diambil.');
    }

    // public function wargaUnpaidPayment(Request $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
    //         'bulan'              => 'nullable|integer|min:1|max:12',
    //         'nama_warga'         => 'nullable|string',
    //     ], [
    //         'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
    //         'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
    //         'bulan.integer'               => 'Bulan harus berupa angka.',
    //         'bulan.min'                   => 'Pilihan bulan minimal adalah bulan ke-1 (Januari).',
    //         'bulan.max'                   => 'Pilihan bulan maksimal adalah bulan ke-12 (Desember).',
    //         'nama_warga.string'           => 'Nama warga harus berupa teks.',
    //     ]);

    //     if ($validator->fails()) {
    //         return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
    //     }

    //     $data  = $validator->validated();
    //     $iuran = InformasiIuran::find($data['id_informasi_iuran']);

    //     if (!$iuran) {
    //         return ApiResponse::error('Iuran tidak ditemukan.', null, 404);
    //     }

    //     if ($iuran->jenis_iuran === 'bulanan' && empty($data['bulan'])) {
    //         return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
    //     }

    //     $user  = Auth::user();
    //     $bulan = $data['bulan'] ?? null;

    //     $query = Warga::with(['anggotaRegu.regu'])
    //         ->whereNull('deleted_at')
    //         // Belum bayar
    //         ->whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
    //             $q->where('id_informasi_iuran', $data['id_informasi_iuran'])
    //                 ->whereIn('status_bayar', ['approved', 'pending']);

    //             if ($iuran->jenis_iuran === 'bulanan') {
    //                 $q->whereJsonContains('bulan', $data['bulan']);
    //             }
    //         })
    //         ->where(function ($q) use ($iuran, $bulan) {
    //             // Warga aktif
    //             $q->where(function ($q1) use ($iuran, $bulan) {
    //                 $q1->where('status_keaktifan', 'aktif');

    //                 // Filter warga baru — hanya wajib bayar mulai bulan bergabung
    //                 if ($iuran->jenis_iuran === 'bulanan' && $bulan) {
    //                     $tahunPeriode = (int) $iuran->periode;
    //                     $q1->where(function ($q2) use ($bulan, $tahunPeriode) {
    //                         // Bergabung sebelum tahun periode — wajib bayar semua bulan
    //                         $q2->whereYear('created_at', '<', $tahunPeriode)
    //                             // Bergabung di tahun periode — wajib bayar mulai bulan bergabung
    //                             ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
    //                                 $q3->whereYear('created_at', $tahunPeriode)
    //                                     ->whereMonth('created_at', '<=', $bulan);
    //                             });
    //                     });
    //                 }
    //             })
    //                 // Warga nonaktif yang masih punya tunggakan sebelum nonaktif
    //                 ->orWhere(function ($q1) use ($iuran, $bulan) {
    //                     $q1->where('status_keaktifan', 'tidak_aktif')
    //                         ->whereNotNull('tanggal_nonaktif');

    //                     if ($iuran->jenis_iuran === 'bulanan' && $bulan) {
    //                         $tahunPeriode = (int) $iuran->periode;
    //                         // Nonaktif setelah bulan yang dicek — masih wajib bayar
    //                         $q1->where(function ($q2) use ($bulan, $tahunPeriode) {
    //                             $q2->whereYear('tanggal_nonaktif', '>', $tahunPeriode)
    //                                 ->orWhere(function ($q3) use ($bulan, $tahunPeriode) {
    //                                     $q3->whereYear('tanggal_nonaktif', $tahunPeriode)
    //                                         ->whereMonth('tanggal_nonaktif', '>', $bulan);
    //                                 });
    //                         });
    //                     }
    //                 });
    //         });

    //     if ($user->role === 'ketua_regu') {
    //         $query->whereHas(
    //             'anggotaRegu.regu',
    //             fn($q) => $q->where('id_user', $user->id)
    //         );
    //     }

    //     if ($request->filled('nama_warga')) {
    //         $query->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
    //     }

    //     $perPage = $request->get('per_page', 10);
    //     $warga   = $query->orderBy('nama_warga')->paginate($perPage);

    //     return ApiResponse::success($warga, 'Daftar warga yang belum melakukan pembayaran berhasil diambil.');
    // }

    public function historyAlreadyPaid(Request $request): JsonResponse
    {
        $request->validate([
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        $data = Pembayaran::with(['informasiIuran', 'diprosesoleh:id,name,role'])
            ->where('nik', $request->nik)
            ->whereIn('status_bayar', ['approved', 'pending'])
            ->orderByDesc('tanggal_bayar')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($data, 'Data riwayat pembayaran berhasil diambil.');
    }

    public function historyNotYetPaid(Request $request): JsonResponse
    {
        $request->validate([
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        $nik = $request->nik;

        $data = InformasiIuran::whereDoesntHave('pembayaran', function ($q) use ($nik) {
            $q->where('nik', $nik)
                ->whereIn('status_bayar', ['approved', 'pending']);
        })
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($data, 'Data iuran yang belum dibayar berhasil diambil.');
    }

    public function getPaidMonth(Request $request): JsonResponse
    {
        $request->validate([
            'id_informasi_iuran' => 'required|integer|exists:informasi_iuran,id',
            'nik'                => 'required|string|exists:warga,nik',
        ], [
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.integer'  => 'ID informasi iuran harus berupa angka.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'nik.required'                => 'NIK wajib diisi.',
            'nik.string'                  => 'NIK harus berupa teks.',
            'nik.exists'                  => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        $warga = Warga::find($request->nik);
        $iuran = InformasiIuran::find($request->id_informasi_iuran);

        $semuaPembayaran = Pembayaran::where('id_informasi_iuran', $request->id_informasi_iuran)
            ->where('nik', $request->nik)
            ->get();

        $priority = ['approved' => 3, 'pending' => 2, 'rejected' => 1, 'cancelled' => 1];

        $statusPerBulan = $semuaPembayaran
            ->groupBy('bulan')
            ->map(function ($records) use ($priority) {
                return $records->sortByDesc(fn($p) => $priority[$p->status_bayar] ?? 0)->first()->status_bayar;
            });

        $bulanApproved  = $statusPerBulan->filter(fn($s) => $s === 'approved')->keys()->sort()->values();
        $bulanPending   = $statusPerBulan->filter(fn($s) => $s === 'pending')->keys()->sort()->values();
        $bulanRejected  = $statusPerBulan->filter(fn($s) => $s === 'rejected')->keys()->sort()->values();
        $bulanCancelled = $statusPerBulan->filter(fn($s) => $s === 'cancelled')->keys()->sort()->values();

        [$bulanMulai, $bulanMaksimal] = $warga->hitungRentangBulanWajib((int) $iuran->periode);

        return ApiResponse::success([
            'bulan_approved'       => $bulanApproved,
            'bulan_pending'        => $bulanPending,
            'bulan_rejected'       => $bulanRejected,
            'bulan_cancelled'      => $bulanCancelled,
            'bulan_mulai_bayar'    => $bulanMulai,
            'bulan_maksimal_bayar' => $bulanMaksimal,
        ], 'Data bulan yang sudah dibayar berhasil diambil.');
    }
    
    public function getUnpaidWargaByLeader(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->role !== 'ketua_regu') {
            return ApiResponse::error('Akses ditolak.', null, 403);
        }

        $query = Warga::with(['anggotaRegu.regu'])
            ->whereHas(
                'anggotaRegu.regu',
                fn($q) =>
                $q->where('id_user', $user->id)
            )
            ->whereHas(
                'pembayaran',
                function ($q) {
                    $q->whereIn('status_bayar', ['pending', 'waiting_payment', 'failed', 'expired', 'canceled']);
                },
            )
            ->orWhere(function ($q) use ($user) {
                $q->whereHas(
                    'anggotaRegu.regu',
                    fn($q2) =>
                    $q2->where('id_user', $user->id)
                )
                    ->whereDoesntHave(
                        'pembayaran',
                        fn($q2) =>
                        $q2->whereIn('status_bayar', ['approved', 'pending'])
                            ->whereHas(
                                'informasiIuran',
                                fn($q3) =>
                                $q3->where('status_aktif', true)->whereNull('deleted_at')
                            )
                    );
            });

        if ($request->filled('nama_warga')) {
            $query->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
        }

        $warga = $query
            ->orderBy('nama_warga')
            ->paginate($request->get('per_page', 10));

        return ApiResponse::success($warga, 'Daftar warga yang masih memiliki iuran belum dibayar berhasil diambil.');
    }

    public function sendUnpaidResidentsNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'month'              => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data  = $validator->validated();
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        if ($iuran->jenis_iuran === 'bulanan' && empty($data['month'])) {
            return ApiResponse::error('Bulan wajib diisi untuk iuran bulanan.', null, 422);
        }

        $wargaList = Warga::with(['user.devices'])
            ->whereDoesntHave('pembayaran', function ($q) use ($data, $iuran) {
                $q->where('id_informasi_iuran', $data['id_informasi_iuran'])
                    ->whereIn('status_bayar', ['approved', 'pending']);

                if ($iuran->jenis_iuran === 'bulanan') {
                    $q->whereJsonContains('bulan', (int) $data['month']);
                }
            })
            ->get();

        if ($wargaList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada warga yang belum bayar untuk iuran ini.');
        }

        $title = 'Pengingat Pembayaran Iuran';

        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $periode = isset($data['month']) ? ($iuran->periode . '-' . str_pad($data['month'], 2, '0', STR_PAD_LEFT)) : null;

        $notifService = app(\App\Services\IuranNotificationService::class);

        $countFirebase = 0;
        $countWhatsapp = 0;
        $countGagal    = 0;

        foreach ($wargaList as $warga) {
            if ($iuran->jenis_iuran === 'bulanan') {
                $bulanLabel = $monthNames[$data['month']] ?? 'bulan ini';
                $message    = "Yth. {$warga->nama_warga}, Anda belum melakukan pembayaran iuran bulanan untuk {$bulanLabel}. Segera lakukan pembayaran melalui aplikasi. Terima kasih.";
            } else {
                $message = "Yth. {$warga->nama_warga}, Anda belum melakukan pembayaran untuk iuran {$iuran->judul_iuran}. Segera lakukan pembayaran melalui aplikasi. Terima kasih.";
            }

            $channel = $notifService->kirimNotifikasi(
                warga: $warga,
                iuran: $iuran,
                type: 'manual',
                periode: $periode,
                title: $title,
                message: $message,
                checkDuplicate: false,
            );

            match ($channel) {
                'firebase' => $countFirebase++,
                'whatsapp' => $countWhatsapp++,
                default    => $countGagal++,
            };
        }

        $this->writeLog(
            'send_notification',
            "Mengirim notifikasi pengingat iuran \"{$iuran->judul_iuran}\" ke {$wargaList->count()} warga (Firebase: {$countFirebase}, WhatsApp: {$countWhatsapp}, Gagal: {$countGagal}).",
            $request
        );

        return ApiResponse::success(
            [
                'total'    => $wargaList->count(),
                'firebase' => $countFirebase,
                'whatsapp' => $countWhatsapp,
                'gagal'    => $countGagal,
            ],
            "Notifikasi berhasil dikirim: {$countFirebase} via aplikasi, {$countWhatsapp} via WhatsApp" .
                ($countGagal > 0 ? ", {$countGagal} gagal terkirim." : '.')
        );
    }

    public function sendResidentNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik'                => 'required|exists:warga,nik',
            'id_informasi_iuran' => 'required|exists:informasi_iuran,id',
            'month'              => 'nullable|integer|min:1|max:12',
        ], [
            'nik.required'                => 'NIK wajib diisi.',
            'nik.exists'                  => 'NIK yang dipilih tidak valid atau tidak ditemukan.',
            'id_informasi_iuran.required' => 'Informasi iuran wajib dipilih.',
            'id_informasi_iuran.exists'   => 'Informasi iuran yang dipilih tidak valid atau tidak ditemukan.',
            'month.integer'               => 'Bulan harus berupa angka.',
            'month.min'                   => 'Pilihan bulan minimal adalah bulan ke-1 (Januari).',
            'month.max'                   => 'Pilihan bulan maksimal adalah bulan ke-12 (Desember).',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $warga = Warga::with('user.devices')->find($data['nik']);
        $iuran = InformasiIuran::find($data['id_informasi_iuran']);

        // Warga wajib punya minimal salah satu channel: device aktif ATAU no_hp
        $adaDevice = $warga->user?->devices?->isNotEmpty() ?? false;

        if (!$adaDevice && !$warga->no_hp) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif maupun nomor HP terdaftar.', null, 404);
        }

        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        if ($iuran->jenis_iuran === 'bulanan') {
            $bulanLabel = isset($data['month']) ? $monthNames[$data['month']] : 'bulan ini';

            $title   = 'Pengingat Iuran Bulanan';
            $message = "Yth. {$warga->nama_warga}, Anda belum melakukan pembayaran iuran bulanan untuk bulan {$bulanLabel}. Segera lakukan pembayaran. Terima kasih.";
        } else {
            $title   = 'Pengingat Iuran Kematian';
            $message = "Yth. {$warga->nama_warga}, Anda belum melakukan pembayaran iuran kematian untuk {$iuran->judul_iuran}. Segera lakukan pembayaran. Terima kasih.";
        }

        $periode = isset($data['month']) ? ($iuran->periode . '-' . str_pad($data['month'], 2, '0', STR_PAD_LEFT)) : null;

        $channel = app(\App\Services\IuranNotificationService::class)->kirimNotifikasi(
            warga: $warga,
            iuran: $iuran,
            type: 'manual',
            periode: $periode,
            title: $title,
            message: $message,
            checkDuplicate: false, // manual oleh admin, jangan di-skip walau sudah pernah dikirim otomatis
        );

        if ($channel === 'gagal') {
            return ApiResponse::error('Gagal mengirim notifikasi ke warga.', null, 500);
        }

        $this->writeLog(
            'send_notification',
            "Mengirim notifikasi manual ke warga {$warga->nama_warga} (NIK: {$warga->nik}) via {$channel}.",
            $request
        );

        $channelLabel = $channel === 'firebase' ? 'aplikasi' : 'WhatsApp';

        return ApiResponse::success(null, "Notifikasi berhasil dikirim ke warga via {$channelLabel}.");
    }

    public function sendAllUnpaidToResident(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::with('user.devices')->find($request->nik);

        $paidIuranIds = Pembayaran::where('nik', $warga->nik)
            ->whereIn('status_bayar', ['approved', 'pending'])
            ->pluck('id_informasi_iuran');

        $unpaidList = InformasiIuran::whereNotIn('id', $paidIuranIds)
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->get();

        if ($unpaidList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada iuran yang belum dibayar.');
        }

        // Warga wajib punya minimal salah satu channel: device aktif ATAU no_hp
        $adaDevice = $warga->user?->devices?->isNotEmpty() ?? false;

        if (!$adaDevice && !$warga->no_hp) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif maupun nomor HP terdaftar.', null, 404);
        }

        $iuranList = $unpaidList->map(fn($i) => "- {$i->judul_iuran}")->implode("\n");
        $message   = "Anda memiliki {$unpaidList->count()} iuran yang belum dibayar:\n\n{$iuranList}\n\nSegera lakukan pembayaran.";
        $title     = 'Pengingat Iuran Belum Dibayar';

        $channel = app(\App\Services\IuranNotificationService::class)
            ->kirimPesanKeWarga($warga, $title, $message);

        if ($channel === 'gagal') {
            return ApiResponse::error('Gagal mengirim notifikasi ke warga.', null, 500);
        }

        $this->writeLog(
            'send_notification',
            "Mengirim ringkasan {$unpaidList->count()} tunggakan iuran ke warga {$warga->nama_warga} via {$channel}.",
            $request
        );

        $channelLabel = $channel === 'firebase' ? 'aplikasi' : 'WhatsApp';

        return ApiResponse::success(null, "Notifikasi ringkasan berhasil dikirim via {$channelLabel}.");
    }

    public function sendUnpaidOneByOneToResident(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nik' => 'required|exists:warga,nik',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.exists'   => 'NIK tidak ditemukan atau tidak terdaftar sebagai warga.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $warga = Warga::with('user.devices')->find($request->nik);

        $paidIuranIds = Pembayaran::where('nik', $warga->nik)
            ->whereIn('status_bayar', ['approved', 'pending'])
            ->pluck('id_informasi_iuran');

        $unpaidList = InformasiIuran::whereNotIn('id', $paidIuranIds)
            ->where('status_aktif', true)
            ->whereNull('deleted_at')
            ->get();

        if ($unpaidList->isEmpty()) {
            return ApiResponse::success(null, 'Tidak ada iuran yang belum dibayar.');
        }

        $tokens = $warga->user?->devices?->pluck('fcm_token')
            ->filter()->unique()->values()->toArray();

        if (empty($tokens)) {
            return ApiResponse::error('Warga tidak memiliki perangkat aktif.', null, 404);
        }

        $firebase = new \App\Services\FirebaseService();

        foreach ($unpaidList as $item) {
            $title   = 'Pengingat Iuran';
            $message = "Iuran {$item->judul_iuran} belum dibayar. Segera lakukan pembayaran.";

            $firebase->sendBulk($tokens, $title, $message);

            NotificationModel::create([
                'title'   => $title,
                'message' => $message,
                'type'    => 'pengingat',
                'user_id' => $warga->user->id,
                'data'    => ['id_informasi_iuran' => $item->id],
            ]);
        }

        // $this->writeLog(
        //     'send_notification',
        //     "Mengirim {$unpaidList->count()} notifikasi tunggakan iuran satu per satu ke warga {$warga->nama_warga}.",
        //     $request
        // );

        return ApiResponse::success(null, 'Notifikasi iuran dikirim satu per satu.');
    }

    public function handleNotification(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            DB::beginTransaction();

            $notification = new Notification();

            $transactionStatus = $notification->transaction_status;
            $paymentType       = $notification->payment_type;
            $orderId           = $notification->order_id;
            $transactionId     = $notification->transaction_id;
            $vaNumbers         = $notification->va_numbers ?? null;
            $qrString          = $notification->qr_string ?? null;
            $fraudStatus       = $notification->fraud_status ?? null;

            $pembayaran = Pembayaran::with(['informasiIuran', 'warga.user.devices'])
                ->where('midtrans_order_id', $orderId)
                ->first();

            if (!$pembayaran) {
                return response()->json(['message' => 'Order tidak ditemukan.'], 404);
            }

            $statusSebelumnya = $pembayaran->status_bayar;

            $pembayaran->status_bayar = match (true) {
                $transactionStatus === 'capture' && $fraudStatus === 'accept'    => 'paid',
                $transactionStatus === 'capture' && $fraudStatus === 'challenge' => 'waiting_payment',
                $transactionStatus === 'settlement'                              => 'paid',
                $transactionStatus === 'pending'                                 => 'waiting_payment',
                $transactionStatus === 'deny'                                    => 'failed',
                $transactionStatus === 'expire'                                  => 'expired',
                $transactionStatus === 'cancel'                                  => 'canceled',
                default                                                          => $pembayaran->status_bayar,
            };

            $pembayaran->midtrans_transaction_id = $transactionId;
            $pembayaran->midtrans_payment_type   = $paymentType;
            $pembayaran->midtrans_va_number      = $vaNumbers[0]['va_number'] ?? null;
            $pembayaran->midtrans_qr_string      = $qrString;
            $pembayaran->midtrans_raw_response   = json_encode($notification);
            $pembayaran->save();

            ActivityLog::create([
                'id_user'            => null,
                'nama_user_snapshot' => 'midtrans-webhook',
                'action'             => 'webhook_midtrans',
                'description'        => "Webhook Midtrans: order {$orderId} → status {$pembayaran->status_bayar}.",
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);

            DB::commit();

            if ($pembayaran->warga && $statusSebelumnya !== $pembayaran->status_bayar) {
                $judul = $pembayaran->informasiIuran?->judul_iuran;

                $pesanStatus = match ($pembayaran->status_bayar) {
                    'paid'     => ["Pembayaran Berhasil ✅", "Pembayaran {$judul} Anda telah berhasil diterima."],
                    'failed'   => ["Pembayaran Gagal ❌", "Pembayaran {$judul} Anda gagal diproses. Silakan coba lagi."],
                    'expired'  => ["Pembayaran Kedaluwarsa ⏰", "Batas waktu pembayaran {$judul} Anda telah habis. Silakan lakukan pembayaran ulang."],
                    'canceled' => ["Pembayaran Dibatalkan ⚠️", "Pembayaran {$judul} Anda telah dibatalkan."],
                    default    => null,
                };

                if ($pesanStatus) {
                    $this->notifyWarga(
                        $pembayaran->warga,
                        $pesanStatus[0],
                        $pesanStatus[1],
                        $pembayaran->status_bayar,
                        ['pembayaran_id' => $pembayaran->id, 'nik' => $pembayaran->warga->nik]
                    );
                }
            }

            return response()->json(['message' => 'OK'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan webhook.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getPembayaranByRegu(Request $request)
    {
        $request->validate([
            'id_informasi_iuran' => 'nullable|exists:informasi_iuran,id',
            'id_regu'            => 'nullable|exists:regu,id',
            'status_bayar'       => 'nullable|in:approved,pending,rejected',
            'nama_warga'         => 'nullable|string', // ← tambah ini
        ]);

        $user     = Auth::user();
        $perPage  = $request->input('per_page', 10);
        $reguId   = null;

        if ($user->role === 'ketua_regu') {
            $reguId = $user->regu()->whereNull('deleted_at')->value('id');

            if (!$reguId) {
                return ApiResponse::success([], 'Data pembayaran berhasil diambil.');
            }
        } elseif ($user->role === 'admin') {
            $reguId = $request->filled('id_regu') ? $request->id_regu : null;
        }

        $query = Pembayaran::with([
            'warga.anggotaRegu.regu',
            'informasiIuran',
            'diprosesoleh',
        ])
            ->whereNull('deleted_at')
            ->when($reguId, function ($q) use ($reguId) {
                $q->whereHas('warga.anggotaRegu', function ($q) use ($reguId) {
                    $q->where('id_regu', $reguId)
                        ->whereNull('deleted_at')
                        ->where('status_keaktifan', 'aktif');
                });
            })
            ->when($request->filled('id_informasi_iuran'), function ($q) use ($request) {
                $q->where('id_informasi_iuran', $request->id_informasi_iuran);
            })
            ->when($request->filled('status_bayar'), function ($q) use ($request) {
                $q->where('status_bayar', $request->status_bayar);
            })
            ->when($request->filled('nama_warga'), function ($q) use ($request) {
                $q->whereHas('warga', function ($q) use ($request) {
                    $q->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
                });
            })
            ->orderByDesc('tanggal_bayar');

        $data = $query->paginate($perPage)->through(function ($item) {
            $anggotaAktif = $item->warga?->anggotaRegu
                ->whereNull('deleted_at')
                ->where('status_keaktifan', 'aktif')
                ->first();

            return [
                'id'                     => $item->id,
                'transaction_id'         => $item->transaction_id,
                'nik'                    => $item->nik,
                'nama_warga'             => $item->nama_warga_snapshot ?? $item->warga?->nama_warga,
                'regu'                   => $anggotaAktif?->regu?->nama_regu,
                'regu_id'                => $anggotaAktif?->regu?->id,
                'informasi_iuran'        => $item->informasiIuran ? [
                    'id'          => $item->informasiIuran->id,
                    'nama'        => $item->informasiIuran->judul_iuran,
                    'jenis_iuran' => $item->informasiIuran->jenis_iuran,
                ] : null,
                'bulan'                  => $item->bulan,
                'jumlah_iuran_snapshot'  => $item->jumlah_iuran_snapshot,
                'total_bayar'            => $item->total_bayar,
                'tanggal_bayar'          => $item->tanggal_bayar?->format('Y-m-d'),
                'metode_bayar'           => $item->metode_bayar,
                'status_bayar'           => $item->status_bayar,
                'processed_by'           => $item->diprosesoleh?->name,
                'bukti_pembayaran'       => $item->bukti_pembayaran,
            ];
        });

        return ApiResponse::success($data, 'Data pembayaran berhasil diambil.');
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $pembayaran = Pembayaran::with(['informasiIuran', 'warga.user.devices'])->find($id);

            if (!$pembayaran) {
                return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
            }

            if ($pembayaran->status_bayar !== Pembayaran::STATUS_PENDING) {
                return ApiResponse::error('Pembayaran ini tidak dalam status pending.', null, 422);
            }

            $pembayaran->update([
                'status_bayar' => Pembayaran::STATUS_APPROVED,
                'validated_by' => Auth::user()->id,
                'validated_at' => now(),
            ]);

            $this->writeLog(
                'update',
                "Pembayaran ID {$pembayaran->id} atas nama {$pembayaran->nama_warga_snapshot} disetujui.",
                $request
            );

            DB::commit();

            $title   = 'Pembayaran Disetujui ✅';
            $message = "Pembayaran {$pembayaran->informasiIuran?->judul_iuran} Anda telah disetujui oleh pengurus.";

            $this->notifyWarga(
                $pembayaran->warga,
                $title,
                $message,
                'approved',
                ['pembayaran_id' => $pembayaran->id, 'nik' => $pembayaran->warga?->nik]
            );

            return ApiResponse::success(null, 'Pembayaran berhasil disetujui.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat menyetujui pembayaran.', $e->getMessage(), 500);
        }
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500',
        ], [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi.',
            'rejection_reason.max'      => 'Alasan penolakan maksimal 500 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $pembayaran = Pembayaran::with(['informasiIuran', 'warga.user.devices'])->find($id);

            if (!$pembayaran) {
                return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
            }

            if ($pembayaran->status_bayar !== Pembayaran::STATUS_PENDING) {
                return ApiResponse::error('Pembayaran ini tidak dalam status pending.', null, 422);
            }

            $pembayaran->update([
                'status_bayar'     => Pembayaran::STATUS_REJECTED,
                'validated_by'     => Auth::user()->id,
                'validated_at'     => now(),
                'rejection_reason' => $request->rejection_reason,
            ]);

            $this->writeLog(
                'update',
                "Pembayaran ID {$pembayaran->id} atas nama {$pembayaran->nama_warga_snapshot} ditolak. Alasan: {$request->rejection_reason}",
                $request
            );

            DB::commit();

            // ── Notifikasi ────────────────────────────────────────────
            $title   = 'Pembayaran Ditolak ❌';
            $message = "Pembayaran {$pembayaran->informasiIuran?->judul_iuran} Anda ditolak. Alasan: {$request->rejection_reason}";

            $this->notifyWarga(
                $pembayaran->warga,
                $title,
                $message,
                'rejected',
                ['pembayaran_id' => $pembayaran->id, 'nik' => $pembayaran->warga?->nik]
            );

            return ApiResponse::success(null, 'Pembayaran berhasil ditolak.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat menolak pembayaran.', $e->getMessage(), 500);
        }
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $pembayaran = Pembayaran::with(['informasiIuran', 'warga.user.devices'])->find($id);

        if (!$pembayaran) {
            return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
        }

        if ($pembayaran->status_bayar !== 'approved') {
            return ApiResponse::error('Hanya pembayaran yang sudah disetujui yang dapat dibatalkan.', null, 422);
        }

        $validator = Validator::make($request->all(), [
            'alasan_pembatalan' => 'required|string|max:500',
        ], [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
            'alasan_pembatalan.max'      => 'Alasan pembatalan maksimal 500 karakter.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $pembayaran->update([
                'status_bayar'      => 'cancelled',
                'rejection_reason'  => $request->alasan_pembatalan,
                'validated_by'      => Auth::user()->id,
                'validated_at'      => now(),
            ]);

            $this->writeLog(
                'update',
                "Membatalkan pembayaran ID {$pembayaran->id} atas nama {$pembayaran->nama_warga_snapshot} dengan alasan: {$request->alasan_pembatalan}.",
                $request
            );

            DB::commit();

            $this->notifyWarga(
                $pembayaran->warga,
                'Pembayaran Dibatalkan ⚠️',
                "Pembayaran {$pembayaran->informasiIuran?->judul_iuran} Anda yang sebelumnya disetujui telah dibatalkan oleh pengurus. Alasan: {$request->alasan_pembatalan}",
                'cancelled',
                ['pembayaran_id' => $pembayaran->id, 'nik' => $pembayaran->warga?->nik]
            );

            return ApiResponse::success(null, 'Pembayaran berhasil dibatalkan.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan saat membatalkan pembayaran.', $e->getMessage(), 500);
        }
    }

    public function riwayatKetuaRegu(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'status_bayar' => 'nullable|in:pending,approved,rejected,cancelled',
            'nama_warga' => 'nullable|string',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', $validator->errors()->first(), 422);
        }

        $perPage = $request->get('per_page', 10);

        $query = Pembayaran::with([
            'warga:nik,nama_warga,no_hp',
            'informasiIuran:id,judul_iuran,jenis_iuran',
            'diprosesoleh:id,name',
        ])
            ->whereNull('deleted_at')
            ->where('processed_by', $user->id);

        if ($request->filled('start_date')) {
            $query->whereDate('tanggal_bayar', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('tanggal_bayar', '<=', $request->end_date);
        }

        if ($request->filled('status_bayar')) {
            $query->where('status_bayar', $request->status_bayar);
        }

        if ($request->filled('nama_warga')) {
            $query->whereHas('warga', function ($q) use ($request) {
                $q->where('nama_warga', 'like', '%' . $request->nama_warga . '%');
            });
        }

        $data = $query
            ->orderByDesc('tanggal_bayar')
            ->paginate($perPage)
            ->through(function ($item) {
                return [
                    'id'              => $item->id,
                    'transaction_id'  => $item->transaction_id,
                    'nik'             => $item->nik,
                    'nama_warga'      => $item->warga->nama_warga      ?? $item->nama_warga_snapshot,
                    'no_hp'           => $item->warga->no_hp           ?? '-',
                    'judul_iuran'     => $item->informasiIuran->judul_iuran ?? '-',
                    'jenis_iuran'     => $item->informasiIuran->jenis_iuran ?? '-',
                    'bulan'           => $item->bulan,
                    'total_bayar'     => $item->total_bayar,
                    'metode_bayar'    => $item->metode_bayar,
                    'status_bayar'    => $item->status_bayar,
                    'tanggal_bayar'   => $item->tanggal_bayar?->format('Y-m-d'),
                    'bukti_pembayaran' => $item->bukti_pembayaran,
                    'rejection_reason' => $item->rejection_reason,
                    'processed_by'    => $item->diprosesoleh?->name,
                    'created_at'      => $item->created_at->format('d/m/Y H:i'),
                ];
            });

        return ApiResponse::success($data, 'Riwayat pembayaran berhasil diambil.');
    }

    public function showById($id): JsonResponse
    {
        $pembayaran = Pembayaran::with([
            'warga:nik,nama_warga,no_hp',
            'warga.anggotaRegu.regu:id,nama_regu',
            'informasiIuran:id,judul_iuran,jenis_iuran,jumlah_iuran',
            'diprosesoleh:id,name,role',
        ])->whereNull('deleted_at')->find($id);

        if (!$pembayaran) {
            return ApiResponse::error('Data pembayaran tidak ditemukan.', null, 404);
        }

        $user = Auth::user();

        // Otorisasi: admin bebas lihat semua, ketua_regu hanya boleh lihat
        // pembayaran dari anggota regu-nya sendiri, warga hanya boleh lihat
        // pembayarannya sendiri.
        if ($user->role === 'ketua_regu') {
            $reguId = $user->regu()->whereNull('deleted_at')->value('id');

            $anggotaValid = $pembayaran->warga?->anggotaRegu
                ?->where('id_regu', $reguId)
                ?->where('status_keaktifan', 'aktif')
                ?->isNotEmpty();

            if (!$reguId || !$anggotaValid) {
                return ApiResponse::error('Anda tidak memiliki akses ke data pembayaran ini.', null, 403);
            }
        } elseif ($user->role === 'warga') {
            if ($pembayaran->nik !== $user->warga?->nik) {
                return ApiResponse::error('Anda tidak memiliki akses ke data pembayaran ini.', null, 403);
            }
        }
        // role 'admin' tidak ada pembatasan tambahan

        $anggotaAktif = $pembayaran->warga?->anggotaRegu
            ?->where('status_keaktifan', 'aktif')
            ?->first();

        $data = [
            'id'               => $pembayaran->id,
            'transaction_id'   => $pembayaran->transaction_id,
            'nik'              => $pembayaran->nik,
            'nama_warga'       => $pembayaran->warga->nama_warga ?? $pembayaran->nama_warga_snapshot,
            'no_hp'            => $pembayaran->warga->no_hp ?? '-',
            'regu'             => $anggotaAktif->regu->nama_regu ?? '-',
            'judul_iuran'      => $pembayaran->informasiIuran->judul_iuran ?? '-',
            'jenis_iuran'      => $pembayaran->informasiIuran->jenis_iuran ?? '-',
            'jumlah_iuran'     => $pembayaran->informasiIuran->jumlah_iuran ?? 0,
            'bulan'            => $pembayaran->bulan,
            'total_bayar'      => $pembayaran->total_bayar,
            'metode_bayar'     => $pembayaran->metode_bayar,
            'status_bayar'     => $pembayaran->status_bayar,
            'tanggal_bayar'    => $pembayaran->tanggal_bayar?->format('Y-m-d'),
            'bukti_pembayaran' => $pembayaran->bukti_pembayaran,
            'note'             => $pembayaran->note,
            'rejection_reason' => $pembayaran->rejection_reason,
            'processed_by'     => $pembayaran->diprosesoleh?->name,
            'submitted_at'     => $pembayaran->submitted_at?->format('d/m/Y H:i'),
            'created_at'       => $pembayaran->created_at->format('d/m/Y H:i'),
        ];

        return ApiResponse::success($data, 'Detail pembayaran berhasil diambil.');
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

    /**
     * Kirim notifikasi terkait status pembayaran ke warga: Firebase dulu,
     * fallback ke WA jika gagal/tidak ada device, lalu simpan riwayat in-app.
     * Kegagalan pengiriman tidak boleh menggagalkan aksi utama (approve/reject/dll),
     * karena itu semua exception ditelan dan hanya dicatat ke log.
     */
    private function notifyWarga($warga, string $title, string $message, string $type, array $data = []): void
    {
        if (!$warga) {
            return;
        }

        try {
            $tokens = collect($warga->user?->devices?->pluck('fcm_token') ?? [])
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $terkirim = false;

            if (!empty($tokens)) {
                try {
                    (new \App\Services\FirebaseService())->sendBulk($tokens, $title, $message);
                    $terkirim = true;
                } catch (\Throwable $e) {
                    Log::warning("Firebase gagal, fallback ke WA: {$e->getMessage()}");
                }
            }

            if (!$terkirim && $warga->no_hp) {
                app(\App\Services\FonnteService::class)->send($warga->no_hp, $message, delay: 15);
            }

            if ($warga->user) {
                NotificationModel::create([
                    'title'   => $title,
                    'message' => $message,
                    'type'    => $type,
                    'user_id' => $warga->user->id,
                    'data'    => $data,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal mengirim notifikasi ke warga {$warga->nik}: {$e->getMessage()}");
        }
    }
}
