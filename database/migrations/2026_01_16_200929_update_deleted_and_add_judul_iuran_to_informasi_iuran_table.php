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
        Schema::table('informasi_iuran', function (Blueprint $table) {
            // hapus field salah
            if (Schema::hasColumn('informasi_iuran', 'id_deleted')) {
                $table->dropColumn('id_deleted');
            }

            // tambah field baru
            $table->boolean('is_deleted')
                  ->default(false)
                  ->after('id');

            $table->string('judul_iuran', 150)
                  ->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('informasi_iuran', function (Blueprint $table) {
            $table->dropColumn(['is_deleted', 'judul_iuran']);

            // rollback field lama (optional)
            $table->unsignedBigInteger('id_deleted')->nullable();
        });
    }
};
