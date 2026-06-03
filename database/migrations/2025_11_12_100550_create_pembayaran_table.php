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
            $table->string('transaction_id')->nullable();
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
                'pending',
                'waiting_payment',
                'paid',
                'failed',
                'expired',
                'canceled',
                'manual',
            ])->default('pending');
            $table->foreignId('processed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->string('midtrans_order_id')->nullable();
            $table->string('midtrans_transaction_id')->nullable();
            $table->string('midtrans_va_number')->nullable();
            $table->text('midtrans_qr_string')->nullable();
            $table->string('midtrans_payment_type')->nullable();
            $table->json('midtrans_raw_response')->nullable();
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