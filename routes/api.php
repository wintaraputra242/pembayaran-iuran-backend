<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WargaController;
use App\Http\Controllers\ReguController;
use App\Http\Controllers\AnggotaReguController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DropdownController;
use App\Http\Controllers\InformasiIuranController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\MidtransController;
use App\Http\Controllers\PembayaranController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Route::get('/csrf-token', function (Request $request) {
//     return response()->json([
//         'csrf_token' => csrf_token(),
//     ]);
// });

// Route::middleware(['web'])->group(function () {
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'user']);
// });

// Route::post('/login', [AuthController::class, 'login']);
// Route::post('/check-nik', [AuthController::class, 'checkNik']);
// Route::post('/set-password', [AuthController::class, 'setPassword']);

// // Callback midtrans
// Route::post('/midtrans/callback', [MidtransController::class, 'handleCallback']);


Route::middleware('auth:sanctum')->group(function () {
//     Route::get('/user', [AuthController::class, 'user']);
//     Route::post('/logout', [AuthController::class, 'logout']);

//     // Admin
    
//     // Warga
//     Route::get('/warga', [WargaController::class, 'index']);
//     Route::get('/warga/{id}', [WargaController::class, 'show']);
//     Route::post('/warga', [WargaController::class, 'store']);
//     Route::put('/warga/{id}', [WargaController::class, 'update']);
//     Route::delete('/warga/{id}', [WargaController::class, 'destroy']);
//     Route::patch('/warga/{id}/status', [WargaController::class, 'updateStatus']);
    
//     // Regu
//     Route::get('/regu', [ReguController::class, 'index']);
//     Route::get('/regu/{id}', [ReguController::class, 'show']);
//     Route::post('/regu', [ReguController::class, 'store']);
//     Route::put('/regu/{id}', [ReguController::class, 'update']);
//     Route::delete('/regu/{id}', [ReguController::class, 'destroy']);

//     // Anggota Regu
//     Route::get('/anggota-regu', [AnggotaReguController::class, 'index']);
//     Route::get('/anggota-regu/{id}', [AnggotaReguController::class, 'show']);
//     Route::post('/anggota-regu', [AnggotaReguController::class, 'store']);
//     Route::delete('/anggota-regu/{id}', [AnggotaReguController::class, 'destroy']);
//     Route::patch('/anggota-regu/{id}/set-leader', [AnggotaReguController::class, 'updateLeader']);

//     // Informasi Iuran
//     Route::get('/informasi-iuran', [InformasiIuranController::class, 'index']);
//     Route::get('/informasi-iuran/{id}', [InformasiIuranController::class, 'show']);
//     Route::post('/informasi-iuran', [InformasiIuranController::class, 'store']);
//     Route::put('/informasi-iuran/{id}', [InformasiIuranController::class, 'update']);
//     Route::delete('/informasi-iuran/{id}', [InformasiIuranController::class, 'destroy']);

//     // Pembayaran Iuran
//     Route::get('/pembayaran', [PembayaranController::class, 'index']);
//     Route::get('/pembayaran/{id}', [PembayaranController::class, 'show']);
//     Route::post('/pembayaran', [PembayaranController::class, 'store']);
//     Route::patch('/pembayaran/status/{id}', [PembayaranController::class, 'updateStatusBayar']);
//     Route::get('/pembayaran/riwayat', [PembayaranController::class, 'riwayat']);

//     // Midtrans
//     Route::post('/midtrans/create-payment', [MidtransController::class, 'createPayment']);
//     Route::get('/midtrans/status/{orderId}', [MidtransController::class, 'checkStatus']);
//     Route::post('/midtrans/cancel/{orderId}', [MidtransController::class, 'cancelPayment']);

//     // Laporan
//     Route::get('/laporan/pembayaran/export', [LaporanController::class, 'exportPembayaran']);
});

Route::middleware(['auth:sanctum', 'role:admin,ketua_regu'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/credential/download', [UserController::class, 'downloadCredentialPdf']);
    // Route::post('/users', [UserController::class, 'store']);
    // Route::get('/users/{user}', [UserController::class, 'show']);
    // Route::put('/users/{user}', [UserController::class, 'update']);
    // Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
    // Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

    // Dropdown
    Route::get('/dropdown/warga', [DropdownController::class, 'getDropdownWarga']);
    Route::get('/dropdown/warga-for-anggota', [DropdownController::class, 'getDropdownWargaForAddAnggota']);
    Route::get('/dropdown/warga-for-pembayaran', [DropdownController::class, 'getDropdownWargaForPembayaran']);
    Route::get('/dropdown/informasi-iuran', [DropdownController::class, 'getDropdownInformasiIuran']);
    Route::get('/dropdown/regu', [DropdownController::class, 'getDropdownRegu']);

    // Warga
    Route::get('/warga', [WargaController::class, 'index']);
    Route::get('/warga/{nik}', [WargaController::class, 'show']);
    Route::post('/warga', [WargaController::class, 'store']);
    Route::put('/warga/{nik}', [WargaController::class, 'update']);
    Route::delete('/warga/{nik}', [WargaController::class, 'destroy']);
    Route::patch('/warga/{nik}/status', [WargaController::class, 'updateStatus']);
    Route::post('/warga/import-excel', [WargaController::class, 'importExcel']);

    // Regu
    Route::get('/regu', [ReguController::class, 'index']);
    Route::get('/regu/{id}', [ReguController::class, 'show']);
    Route::post('/regu', [ReguController::class, 'store']);
    Route::put('/regu/{id}', [ReguController::class, 'update']);
    Route::delete('/regu/{id}', [ReguController::class, 'destroy']);
    Route::patch('/regu/{id}/status', [ReguController::class, 'updateStatus']);

    // Anggota Regu
    Route::get('/anggota-regu', [AnggotaReguController::class, 'index']);
    Route::get('/anggota-regu/{id}', [AnggotaReguController::class, 'show']);
    Route::post('/anggota-regu', [AnggotaReguController::class, 'store']);
    Route::delete('/anggota-regu/{id}', [AnggotaReguController::class, 'destroy']);
    Route::put('/anggota-regu/set-leader', [AnggotaReguController::class, 'setLeader']);
    Route::delete('/anggota-regu/reset/{id}', [AnggotaReguController::class, 'resetAnggota']);
    Route::delete('/anggota-regu/reset-regu/{idRegu}', [AnggotaReguController::class, 'resetAnggotaByRegu']);
    Route::delete('/anggota-regu-reset-all', [AnggotaReguController::class, 'resetAllAnggota']);

    // Informasi Iuran
    Route::get('/informasi-iuran', [InformasiIuranController::class, 'index']);
    Route::get('/informasi-iuran/{id}', [InformasiIuranController::class, 'show']);
    Route::post('/informasi-iuran', [InformasiIuranController::class, 'store']);
    Route::put('/informasi-iuran/{id}', [InformasiIuranController::class, 'update']);
    Route::delete('/informasi-iuran/{id}', [InformasiIuranController::class, 'destroy']);
    Route::patch('/informasi-iuran/{id}/status', [InformasiIuranController::class, 'updateStatus']);

    // master data regu
    // Route::post('/regu/credential/download', [ReguController::class, 'downloadCredentialPdf']);

    // Pembayaran Iuran
    Route::get('/pembayaran', [PembayaranController::class, 'index']);
    Route::get('/pembayaran/{id}', [PembayaranController::class, 'show']);
    Route::post('/pembayaran', [PembayaranController::class, 'store']);
    Route::get('/pembayaran-unpaid-payment', [PembayaranController::class, 'wargaUnpaidPayment']);
    Route::post('/pembayaran-notify-unpaid', 
    
    // notifikasi untuk halaman cek belum bayar
    [PembayaranController::class, 'sendUnpaidResidentsNotification']);
    Route::post('/pembayaran-notify-resident', [PembayaranController::class, 'sendResidentNotification']); 
    //=========================================

    Route::get('/pembayaran-history-paid', [PembayaranController::class, 'historyAlreadyPaid']);
    Route::get('/pembayaran-history-unpaid', [PembayaranController::class, 'historyNotYetPaid']);

    // notifikasi untuk halaman riwayat pembayaran warga
    Route::post('/pembayaran-notify-resident-all-unpaid', [PembayaranController::class, 'sendAllUnpaidToResident']);
    Route::post('/pembayaran-notify-resident-one-by-one', [PembayaranController::class, 'sendUnpaidOneByOneToResident']);
    //=========================================

    Route::get('/pembayaran-paid-month', [PembayaranController::class, 'getPaidMonth']);


    // Laporan
    Route::get('/laporan', [LaporanController::class, 'index']);
    Route::get('/laporan/export-excel', [LaporanController::class, 'exportExcel']);

    // Aktivitas
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/activity-logs/{id}', [ActivityLogController::class, 'show']);

    Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('unread-count', 'unreadCount');
        Route::patch('read-all', 'markAllAsRead');
        Route::patch('{id}/read', 'markAsRead');
    });

    Route::get('/dashboard', [DashboardController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'role:ketua_regu'])->group(function () {
    Route::get('/dropdown/anggota-regu', [DropdownController::class, 'getDropdownAnggotaRegu']);
});

// Route::middleware(['auth:sanctum', 'role:ketua_regu'])->group(function () {
//     Route::get('/dropdown/warga-for-pembayaran', [DropdownController::class, 'getDropdownWargaForPembayaran']);

//     Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
//         Route::get('/', 'index');
//         Route::get('unread-count', 'unreadCount');
//         Route::patch('read-all', 'markAllAsRead');
//         Route::patch('{id}/read', 'markAsRead');
//     });

//     Route::get('/informasi-iuran', [InformasiIuranController::class, 'index']);
//     Route::get('/informasi-iuran/{id}', [InformasiIuranController::class, 'show']);
// });


//midtrans
Route::post('/pembayaran-midtrans-notification', [PembayaranController::class, 'handleNotification']);


