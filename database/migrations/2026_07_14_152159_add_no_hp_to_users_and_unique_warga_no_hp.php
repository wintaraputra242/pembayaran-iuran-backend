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
        // Tambah no_hp di users
        Schema::table('users', function (Blueprint $table) {
            $table->string('no_hp', 20)->nullable()->unique()->after('username');
        });

        // Jadikan no_hp di warga unique
        Schema::table('warga', function (Blueprint $table) {
            $table->unique('no_hp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('no_hp');
        });

        Schema::table('warga', function (Blueprint $table) {
            $table->dropUnique(['no_hp']);
        });
    }
};
