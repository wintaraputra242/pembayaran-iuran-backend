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
        \Carbon\Carbon::setLocale('id');

        User::observe(UserObserver::class);
        Regu::observe(ReguObserver::class);
        Warga::observe(WargaObserver::class);
        InformasiIuran::observe(InformasiIuranObserver::class);
    }
}
