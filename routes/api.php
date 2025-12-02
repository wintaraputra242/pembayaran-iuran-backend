<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WargaController;
use App\Http\Controllers\ReguController;
use App\Http\Controllers\AnggotaReguController;
use App\Http\Controllers\InformasiIuranController;
use App\Http\Controllers\MidtransController;
use App\Http\Controllers\PembayaranController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/check-nik', [AuthController::class, 'checkNik']);
Route::post('/set-password', [AuthController::class, 'setPassword']);

// Callback midtrans
Route::post('/midtrans/callback', [MidtransController::class, 'handleCallback']);


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Admin
    
    // Warga
    Route::get('/warga', [WargaController::class, 'index']);
    Route::get('/warga/{id}', [WargaController::class, 'show']);
    Route::post('/warga', [WargaController::class, 'store']);
    Route::put('/warga/{id}', [WargaController::class, 'update']);
    Route::delete('/warga/{id}', [WargaController::class, 'destroy']);
    Route::patch('/warga/{id}/status', [WargaController::class, 'updateStatus']);
    
    // Regu
    Route::get('/regu', [ReguController::class, 'index']);
    Route::get('/regu/{id}', [ReguController::class, 'show']);
    Route::post('/regu', [ReguController::class, 'store']);
    Route::put('/regu/{id}', [ReguController::class, 'update']);
    Route::delete('/regu/{id}', [ReguController::class, 'destroy']);

    // Anggota Regu
    Route::get('/anggota-regu', [AnggotaReguController::class, 'index']);
    Route::get('/anggota-regu/{id}', [AnggotaReguController::class, 'show']);
    Route::post('/anggota-regu', [AnggotaReguController::class, 'store']);
    Route::delete('/anggota-regu/{id}', [AnggotaReguController::class, 'destroy']);
    Route::patch('/anggota-regu/{id}/set-leader', [AnggotaReguController::class, 'updateLeader']);

    // Informasi Iuran
    Route::get('/informasi-iuran', [InformasiIuranController::class, 'index']);
    Route::get('/informasi-iuran/{id}', [InformasiIuranController::class, 'show']);
    Route::post('/informasi-iuran', [InformasiIuranController::class, 'store']);
    Route::put('/informasi-iuran/{id}', [InformasiIuranController::class, 'update']);
    Route::delete('/informasi-iuran/{id}', [InformasiIuranController::class, 'destroy']);

    // Pembayaran Iuran
    Route::get('/pembayaran', [PembayaranController::class, 'index']);
    Route::get('/pembayaran/{id}', [PembayaranController::class, 'show']);
    Route::post('/pembayaran', [PembayaranController::class, 'store']);
    Route::patch('/pembayaran/status/{id}', [PembayaranController::class, 'updateStatusBayar']);
    Route::get('/pembayaran/riwayat', [PembayaranController::class, 'riwayat']);

    // Midtrans
    Route::post('/midtrans/create-payment', [MidtransController::class, 'createPayment']);
    Route::get('/midtrans/status/{orderId}', [MidtransController::class, 'checkStatus']);
    Route::post('/midtrans/cancel/{orderId}', [MidtransController::class, 'cancelPayment']);

});

