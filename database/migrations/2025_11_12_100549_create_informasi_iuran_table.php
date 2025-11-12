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
        Schema::create('informasi_iuran', function (Blueprint $table) {
            $table->id('id_informasi_iuran');
            $table->enum('jenis_iuran', ['bulanan','kematian']);
            $table->string('periode')->nullable(); // contoh: "Jan-2025" atau "2025" (jika perlu)
            $table->bigInteger('jumlah_iuran')->default(0); // simpan dalam satuan rupiah (integer)
            $table->text('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('informasi_iuran');
    }
};
