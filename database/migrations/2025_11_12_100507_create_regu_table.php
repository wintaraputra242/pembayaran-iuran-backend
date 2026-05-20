<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regu', function (Blueprint $table) {
            $table->id();
            $table->string('nama_regu');
            $table->enum('status_keaktifan', ['aktif', 'tidak_aktif'])->default('aktif');
            $table->foreignId('id_user')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('regu');
    }
};