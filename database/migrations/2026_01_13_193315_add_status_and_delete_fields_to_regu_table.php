<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regu', function (Blueprint $table) {

            // status aktif / nonaktif
            $table->enum('status_keaktifan', ['aktif', 'tidak_aktif'])
                ->default('aktif')
                ->after('nama_regu');

            // penanda akan dihapus permanen
            $table->boolean('is_deleted')
                ->default(false)
                ->after('status_keaktifan');

            // tanggal mulai nonaktif
            $table->date('tanggal_nonaktif')
                ->nullable()
                ->after('is_deleted');

            // waktu ditandai untuk dihapus
            $table->timestamp('deleted_at')
                ->nullable()
                ->after('tanggal_nonaktif');
        });
    }

    public function down(): void
    {
        Schema::table('regu', function (Blueprint $table) {
            $table->dropColumn([
                'status_keaktifan',
                'is_deleted',
                'tanggal_nonaktif',
                'deleted_at',
            ]);
        });
    }
};
