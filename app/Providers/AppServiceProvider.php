<?php

namespace App\Providers;

use App\Models\InformasiIuran;
use App\Models\Regu;
use App\Models\User;
use App\Models\Warga;
use App\Observers\InformasiIuranObserver;
use App\Observers\ReguObserver;
use App\Observers\UserObserver;
use App\Observers\WargaObserver;
use Illuminate\Support\ServiceProvider;
use Midtrans\Config;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        Regu::observe(ReguObserver::class);
        Warga::observe(WargaObserver::class);
        InformasiIuran::observe(InformasiIuranObserver::class);

        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');
    }
}
