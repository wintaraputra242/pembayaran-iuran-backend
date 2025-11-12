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
        Schema::create('regu', function (Blueprint $table) {
            $table->id('id_regu');
            $table->string('nama_regu');
            $table->unsignedBigInteger('id_ketua')->nullable();;
            $table->timestamps();

            $table->foreign('id_ketua')->references('id_user')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regu');
    }
};
