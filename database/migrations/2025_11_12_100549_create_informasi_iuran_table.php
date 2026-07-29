<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informasi_iuran', function (Blueprint $table) {
            $table->id();
            $table->string('judul_iuran', 150);
            $table->enum('jenis_iuran', ['bulanan', 'kematian']);
            $table->string('periode')->nullable();
            $table->bigInteger('jumlah_iuran')->default(0);
            $table->text('keterangan')->nullable();
            $table->string('nama_warga_meninggal', 150)->nullable();
            $table->string('nik_penanggung_jawab', 32)->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('nik_penanggung_jawab')
                ->references('nik')
                ->on('warga')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informasi_iuran');
    }
};
