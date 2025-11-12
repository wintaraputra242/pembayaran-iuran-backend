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
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->id('id_pembayaran');
            $table->foreignId('id_warga')->constrained('warga','id_warga')->cascadeOnDelete();
            $table->foreignId('id_informasi_iuran')->constrained('informasi_iuran','id_informasi_iuran')->cascadeOnDelete();
            $table->date('tanggal_bayar');
            $table->bigInteger('total_bayar')->default(0);
            $table->string('metode_bayar')->nullable(); // contoh: tunai, qris, transfer
            $table->enum('status_bayar', ['pending','lunas','batal'])->default('pending');
            $table->string('bukti_pembayaran')->nullable(); // path file jika upload bukti
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
    }
};
