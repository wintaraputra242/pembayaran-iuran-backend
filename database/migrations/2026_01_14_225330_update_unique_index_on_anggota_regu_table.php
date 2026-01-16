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
        Schema::table('anggota_regu', function (Blueprint $table) {

            // 1️⃣ DROP FOREIGN KEY
            $table->dropForeign(['nik']);
            $table->dropForeign(['id_regu']);

            // 2️⃣ DROP UNIQUE INDEX LAMA
            $table->dropUnique('anggota_regu_id_regu_nik_unique');

            // 3️⃣ BUAT UNIQUE INDEX BARU (soft delete friendly)
            $table->unique(
                ['id_regu', 'nik', 'deleted_at'],
                'anggota_regu_unique_active'
            );

            // 4️⃣ PASANG LAGI FOREIGN KEY
            $table->foreign('nik')
                ->references('nik')
                ->on('warga')
                ->onDelete('cascade');

            $table->foreign('id_regu')
                ->references('id')
                ->on('regu')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('anggota_regu', function (Blueprint $table) {

            $table->dropForeign(['nik']);
            $table->dropForeign(['id_regu']);

            $table->dropUnique('anggota_regu_unique_active');

            $table->unique(
                ['id_regu', 'nik'],
                'anggota_regu_id_regu_nik_unique'
            );

            $table->foreign('nik')
                ->references('nik')
                ->on('warga')
                ->onDelete('cascade');

            $table->foreign('id_regu')
                ->references('id')
                ->on('regu')
                ->onDelete('cascade');
        });
    }
};
