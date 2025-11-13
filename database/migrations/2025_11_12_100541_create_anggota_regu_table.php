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
        Schema::create('anggota_regu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_regu')->constrained('regu','id')->cascadeOnDelete();
            $table->string('nik', 32);
            $table->foreign('nik')->references('nik')->on('warga')->cascadeOnDelete();
            $table->enum('status_keaktifan', ['aktif','tidak_aktif'])->default('aktif');
            $table->boolean('is_leader')->default(false);
            $table->timestamps();

            $table->unique(['id_regu','nik']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anggota_regu');
    }
};
