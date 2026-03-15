<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('informasi_iuran', function (Blueprint $table) {
            // Nama warga yang meninggal
            $table->string('nama_warga_meninggal', 150)
                  ->nullable()
                  ->after('judul_iuran');

            // NIK keluarga penanggung jawab
            $table->string('nik_penanggung_jawab', 20)
                  ->nullable()
                  ->after('nama_warga_meninggal');

            // Foreign key ke tabel warga
            $table->foreign('nik_penanggung_jawab')
                  ->references('nik')
                  ->on('warga')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('informasi_iuran', function (Blueprint $table) {
            $table->dropForeign(['nik_penanggung_jawab']);
            $table->dropColumn([
                'nama_warga_meninggal',
                'nik_penanggung_jawab'
            ]);
        });
    }
};

