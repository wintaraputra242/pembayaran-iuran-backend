<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('informasi_iuran', function (Blueprint $table) {
            $table->id();
            $table->enum('jenis_iuran', ['bulanan','kematian']);
            $table->string('periode')->nullable();
            $table->bigInteger('jumlah_iuran')->default(0);
            $table->text('keterangan')->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('informasi_iuran');
    }
};
