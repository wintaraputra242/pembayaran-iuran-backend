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
        Schema::create('warga', function (Blueprint $table) {
            $table->id('id_warga');
            $table->string('nik', 32)->unique();
            $table->string('nama_warga');
            $table->string('alamat')->nullable();
            $table->string('no_hp')->nullable();
            $table->foreignId('id_regu')->nullable()->constrained('regu','id_regu')->nullOnDelete();
            $table->string('password'); // warga punya password untuk login
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warga');
    }
};
