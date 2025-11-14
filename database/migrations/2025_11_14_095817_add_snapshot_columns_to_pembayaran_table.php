<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            // Tambah kolom snapshot (nullable supaya aman untuk data lama)
            $table->string('nik_snapshot', 32)->nullable()->after('nik');
            $table->string('nama_warga_snapshot')->nullable()->after('nik_snapshot');
        });
    }

    public function down()
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn(['nik_snapshot', 'nama_warga_snapshot']);
        });
    }
};
