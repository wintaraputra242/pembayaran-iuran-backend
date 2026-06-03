<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WargaController;
use App\Http\Controllers\ReguController;
use App\Http\Controllers\AnggotaReguController;
use App\Http\Controllers\Client\AnggotaReguController as ClientAnggotaReguController;
use App\Http\Controllers\Client\AuthController as ClientAuthController;
use App\Http\Controllers\Client\InformasiIuranController as ClientInformasiIuranController;
use App\Http\Controllers\Client\NotificationController as ClientNotificationController;
use App\Http\Controllers\Client\PembayaranController as ClientPembayaranController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DropdownController;
use App\Http\Controllers\InformasiIuranController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\MidtransController;
use App\Http\Controllers\PembayaranController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Auth (public)
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/login',      'login');
});

// Auth client (public)
Route::prefix('client/auth')->controller(ClientAuthController::class)->group(function () {
    Route::post('/check-nik',  'checkNik');
    Route::post('/login',      'login');
});

// Midtrans callback (public, tidak perlu auth)
Route::prefix('midtrans')->controller(MidtransController::class)->group(function () {
    Route::post('/callback', 'handleCallback');
});

// Pembayaran Midtrans notification (public)
Route::post('/pembayaran-midtrans-notification', [PembayaranController::class, 'handleNotification']);

Route::middleware('auth:sanctum')->group(function () {

    // Auth (middleware)
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('/logout',     'logout');
        Route::post('/logout-all', 'logoutAll');
        Route::get('/me',          'user');
    });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Activity Log
    Route::prefix('activity-logs')->controller(ActivityLogController::class)->group(function () {
        Route::get('/',      'index');
        Route::get('/{id}',  'show');
    });

    // Dropdown
    Route::prefix('dropdown')->controller(DropdownController::class)->group(function () {
        Route::get('/warga',                  'getDropdownWarga');
        Route::get('/warga-for-anggota',      'getDropdownWargaForAddAnggota');
        Route::get('/warga-for-pembayaran',   'getDropdownWargaForPembayaran');
        Route::get('/informasi-iuran',        'getDropdownInformasiIuran');
        Route::get('/regu',                   'getDropdownRegu');
        Route::get('/anggota-regu',           'getDropdownAnggotaRegu');
    });

    // Users
    Route::prefix('users')->controller(UserController::class)->group(function () {
        Route::get('/',                     'index');
        Route::get('/credential/download',  'downloadCredentialPdf');
        Route::get('/{id}',                 'show');
        Route::put('/{id}',                 'update');
        // Route::delete('/{id}',              'destroy');
        // Route::patch('/{id}/toggle-active', 'toggleActive');
    });

    // Warga
    Route::prefix('warga')->controller(WargaController::class)->group(function () {
        Route::get('/',                'index');
        Route::post('/',               'store');
        Route::post('/import-excel',   'importExcel'); // ini request tanpa menggunakan header Accept: application/json
        Route::get('/template-import', 'exportTemplate');
        Route::get('/{nik}',           'show');
        Route::put('/{nik}',           'update');
        Route::delete('/{nik}',        'destroy');
        Route::patch('/{nik}/status',  'updateStatus');
    });

    // Regu
    Route::prefix('regu')->controller(ReguController::class)->group(function () {
        Route::get('/',                       'index');
        Route::post('/',                      'store');
        Route::get('/{id}',                   'show');
        Route::put('/{id}',                   'update');
        Route::delete('/{id}',                'destroy');
        Route::patch('/{id}/status',          'updateStatus');
    });

    // Anggota Regu
    Route::prefix('anggota-regu')->controller(AnggotaReguController::class)->group(function () {
        Route::get('/',                        'index');
        Route::post('/',                       'store');
        Route::put('/set-leader',              'setLeader');
        Route::delete('/reset-all',            'resetAllAnggota');
        Route::get('/{id}',                    'show');
        Route::delete('/{id}',                 'destroy');
        Route::delete('/reset/{id}',           'resetAnggota');
        Route::delete('/reset-regu/{idRegu}',  'resetAnggotaByRegu');
    });

    // Informasi Iuran
    Route::prefix('informasi-iuran')->controller(InformasiIuranController::class)->group(function () {
        Route::get('/active',   'getActiveInformasiIuranForPayment');
        Route::get('/',         'index');
        Route::post('/',        'store');
        Route::get('/{id}',     'show');
        Route::put('/{id}',     'update');
        Route::delete('/{id}',  'destroy');
        Route::patch('/{id}/status', 'updateStatus');
    });

    // Pembayaran
    Route::prefix('pembayaran')->controller(PembayaranController::class)->group(function () {
        Route::get('/',                         'index');
        Route::post('/',                        'store');
        Route::get('/unpaid',                   'wargaUnpaidPayment');
        Route::get('/unpaid-by-leader',         'getUnpaidWargaByLeader');
        Route::get('/history-paid',             'historyAlreadyPaid');
        Route::get('/history-unpaid',           'historyNotYetPaid');
        Route::get('/paid-month',               'getPaidMonth');
        Route::post('/notify-unpaid',           'sendUnpaidResidentsNotification');
        Route::post('/notify-resident',         'sendResidentNotification');
        Route::post('/notify-all-unpaid',       'sendAllUnpaidToResident');
        Route::post('/notify-one-by-one',       'sendUnpaidOneByOneToResident');
        Route::get('/by-regu',                  'getPembayaranByRegu');
        Route::get('/{nik}',                    'show');
    });

    // Midtrans (authenticated)
    Route::prefix('midtrans')->controller(MidtransController::class)->group(function () {
        Route::post('/pay',              'createPayment');
        Route::get('/status/{orderId}',  'checkStatus');
        Route::post('/cancel/{orderId}', 'cancelPayment');
    });

    // Laporan
    Route::prefix('laporan')->controller(LaporanController::class)->group(function () {
        Route::get('/',              'index');
        Route::get('/export-excel',  'exportExcel');
    });

    // Notifications
    Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
        Route::get('/',             'index');
        Route::get('/unread-count', 'unreadCount');
        Route::patch('/read-all',   'markAllAsRead');
        Route::patch('/{id}/read',  'markAsRead');
    });


    // ===== CLIENT ===== //
    // Auth
    Route::prefix('client/auth')->controller(ClientAuthController::class)->group(function () {
        Route::post('/logout',          'logout');
        Route::get('/profile',          'profile');         
        Route::put('/profile',          'updateProfile'); 
    });

    // Notifications
    Route::prefix('client/notifications')->controller(ClientNotificationController::class)->group(function () {
        Route::get('/',             'index');
        Route::get('/unread-count', 'unreadCount');
        Route::patch('/read-all',   'markAllAsRead');
        Route::patch('/{id}/read',  'markAsRead');
    });

    // Informasi Iuran
    Route::prefix('client/informasi-iuran')->controller(ClientInformasiIuranController::class)->group(function () {
        Route::get('/',        'getIuranWithStatus');
        Route::get('/{id}',    'show');
    });

    // Pembayaran
    Route::prefix('client/pembayaran')->controller(ClientPembayaranController::class)->group(function () {
        Route::post('/',                  'payment');
        Route::get('/riwayat',            'getHistories');
        Route::get('/paid-months',        'getPaidMonths');
    });

    // Anggota Regu
    Route::prefix('client/anggota-regu')->controller(ClientAnggotaReguController::class)->group(function () {
        Route::get('/',                   'getAnggotaRegu');
    });
});
