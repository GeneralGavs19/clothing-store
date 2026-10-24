<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'is_on_order')) {
                $table->boolean('is_on_order')->default(false)->after('status')->index();
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'is_on_order')) {
                $table->boolean('is_on_order')->default(false)->after('quantity')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'is_on_order')) {
                $table->dropColumn('is_on_order');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'is_on_order')) {
                $table->dropColumn('is_on_order');
            }
        });
    }
};
