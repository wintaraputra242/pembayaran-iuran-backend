<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('nik', 32);
            $table->foreignId('id_informasi_iuran')->constrained('informasi_iuran')->cascadeOnDelete();
            $table->string('type'); // bulanan_awal, bulanan_tengah, bulanan_akhir, kematian_h1, kematian_7, kematian_30
            $table->string('periode')->nullable(); // untuk bulanan: "2025-07", untuk kematian: null
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            // Mencegah double kirim notifikasi yang sama
            $table->unique(['nik', 'id_informasi_iuran', 'type', 'periode'], 'unique_notification');

            $table->foreign('nik')->references('nik')->on('warga')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};