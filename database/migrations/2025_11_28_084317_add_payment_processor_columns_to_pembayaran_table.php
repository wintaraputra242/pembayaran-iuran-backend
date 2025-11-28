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
        Schema::table('pembayaran', function (Blueprint $table) {
            // tidak boleh mengubah enum dulu — nanti error
        });

        // 1️⃣ Update nilai lama agar cocok dengan ENUM baru
        DB::table('pembayaran')->where('status_bayar', 'lunas')->update([
            'status_bayar' => 'paid'
        ]);

        DB::table('pembayaran')->where('status_bayar', 'batal')->update([
            'status_bayar' => 'canceled'
        ]);

        // 2️⃣ Baru ubah ENUM
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->enum('status_bayar', [
                'pending',
                'waiting_payment',
                'paid',
                'failed',
                'expired',
                'canceled',
                'manual'
            ])->default('pending')->change();
        });

        // 3️⃣ Lanjutkan kolom lainnya seperti biasa
        Schema::table('pembayaran', function (Blueprint $table) {

            $table->bigInteger('jumlah_iuran_snapshot')
                ->nullable()
                ->after('nama_warga_snapshot');

            $table->json('bulan')
                ->nullable()
                ->after('jumlah_iuran_snapshot');

            $table->date('tanggal_bayar')
                ->nullable()
                ->change();

            $table->string('midtrans_order_id')->nullable()->after('status_bayar');
            $table->string('midtrans_transaction_id')->nullable()->after('midtrans_order_id');
            $table->string('midtrans_va_number')->nullable()->after('midtrans_transaction_id');
            $table->text('midtrans_qr_string')->nullable()->after('midtrans_va_number');
            $table->string('midtrans_payment_type')->nullable()->after('midtrans_qr_string');
            $table->json('midtrans_raw_response')->nullable()->after('midtrans_payment_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn([
                'jumlah_iuran_snapshot',
                'bulan',
                'midtrans_order_id',
                'midtrans_transaction_id',
                'midtrans_va_number',
                'midtrans_qr_string',
                'midtrans_payment_type',
                'midtrans_raw_response'
            ]);

            // rollback enum status_bayar ke awal
            $table->enum('status_bayar', ['pending','lunas','batal'])
                ->default('pending')
                ->change();

            // rollback tanggal_bayar
            $table->date('tanggal_bayar')
                ->nullable(false)
                ->change();
        });
    }
};
