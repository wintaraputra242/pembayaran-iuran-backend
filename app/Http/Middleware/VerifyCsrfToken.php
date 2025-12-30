<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Support\Facades\Log;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs yang dikecualikan dari verifikasi CSRF.
     */
    protected $except = [
        // kalau mau disable CSRF untuk auth
        // 'auth/login',
        // 'auth/logout',
    ];

    protected function tokensMatch($request)
    {
        Log::info('CSRF DEBUG', [
            'session_id' => $request->session()->getId(),
            'session_token' => $request->session()->token(),
            'xsrf_cookie' => $request->cookie('XSRF-TOKEN'),
            'header' => $request->header('X-XSRF-TOKEN'),
        ]);

        return parent::tokensMatch($request);
    }
}
