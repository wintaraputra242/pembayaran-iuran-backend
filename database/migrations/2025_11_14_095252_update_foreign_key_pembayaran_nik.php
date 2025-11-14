<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('pembayaran', function (Blueprint $table) {

            // ubah kolom nik jadi nullable
            $table->string('nik', 32)->nullable()->change();

            // hapus foreign key lama
            $table->dropForeign(['nik']);

            // buat foreign key baru
            $table->foreign('nik')
                ->references('nik')
                ->on('warga')
                ->nullOnDelete(); // atau ->onDelete('set null')
        });
    }

    public function down()
    {
        Schema::table('pembayaran', function (Blueprint $table) {

            // rollback ke kondisi awal
            $table->dropForeign(['nik']);

            $table->string('nik', 32)->change(); // tidak nullable lagi

            $table->foreign('nik')
                ->references('nik')
                ->on('warga')
                ->cascadeOnDelete(); // kondisi awal
        });
    }

};
