<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Hapus FK lama
            $table->dropForeign(['nik']);

            // Buat FK baru dengan ON UPDATE CASCADE
            $table->foreign('nik')
                ->references('nik')->on('warga')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            // Balik ke FK tanpa update cascade (jika rollback)
            $table->dropForeign(['nik']);

            $table->foreign('nik')
                ->references('nik')->on('warga')
                ->onDelete('cascade');
        });
    }
};
