<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReguController;
use App\Http\Controllers\UserController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->get(
    '/regu/credential/download',
    [UserController::class, 'downloadCredentialPdf']
);
