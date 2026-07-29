<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->id();
            $table->string('nik', 32)->nullable();
            $table->foreignId('id_informasi_iuran')
                  ->constrained('informasi_iuran')
                  ->restrictOnDelete();
            $table->string('nik_snapshot', 32)->nullable();
            $table->string('nama_warga_snapshot')->nullable();
            $table->bigInteger('jumlah_iuran_snapshot')->nullable();
            $table->json('bulan')->nullable();
            $table->date('tanggal_bayar')->nullable();
            $table->bigInteger('total_bayar')->default(0);
            $table->string('metode_bayar')->nullable();
            $table->enum('status_bayar', [
                'pending',      // sudah submit, menunggu validasi pengurus
                'approved',     // disetujui pengurus
                'rejected',     // ditolak pengurus
            ])->default('pending');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('processed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('validated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('bukti_pembayaran')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('nik')
                  ->references('nik')
                  ->on('warga')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
    }
};