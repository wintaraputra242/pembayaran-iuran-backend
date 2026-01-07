<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // hapus field yang tidak dipakai
             $table->dropForeign('users_nik_foreign');

            $table->dropColumn(['email', 'email_verified_at', 'nik']);

            // tambah is_active
            $table->boolean('is_active')
                  ->default(true)
                  ->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();

             $table->foreign('nik')
                  ->references('nik')
                  ->on('warga');

            $table->dropColumn('is_active');
        });
    }
};

