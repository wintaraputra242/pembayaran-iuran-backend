<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->get(
    '/regu/credential/download',
    [UserController::class, 'downloadCredentialPdf']
);
