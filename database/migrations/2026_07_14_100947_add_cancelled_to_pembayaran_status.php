<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE pembayaran MODIFY COLUMN status_bayar ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pembayaran MODIFY COLUMN status_bayar ENUM('pending','approved','rejected') DEFAULT 'pending'");
    }
};
