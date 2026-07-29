<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warga', function (Blueprint $table) {
            $table->string('nik', 32)->primary();
            $table->foreignId('id_user')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->string('nama_warga');
            $table->string('alamat')->nullable();
            $table->string('no_hp')->nullable();
            $table->enum('status_keaktifan', ['aktif', 'tidak_aktif'])->default('aktif');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('warga');
    }
};