<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_plain', 255)->nullable()->after('password');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin_programmer', 'admin', 'cashier') NOT NULL DEFAULT 'cashier'");
        }

        $adminEmail = env('ADMIN_EMAIL', 'admin@store.local');
        DB::table('users')->where('email', $adminEmail)->update(['role' => 'admin_programmer']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'admin_programmer')->update(['role' => 'admin']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'cashier') NOT NULL DEFAULT 'cashier'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_plain');
        });
    }
};
