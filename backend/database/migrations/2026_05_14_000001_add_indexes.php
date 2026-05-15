<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(['status', 'approved_at']);
                $table->index('approved_at');
            });
        } catch (\Exception $e) {
            // index may already exist on some DBs
        }

        try {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->index('sale_id');
                $table->index('product_id');
            });
        } catch (\Exception $e) {
            // ignore
        }

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->index('status');
            });
        } catch (\Exception $e) {
            // ignore
        }
    }

    public function down(): void
    {
        try {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['status', 'approved_at']);
                $table->dropIndex(['approved_at']);
            });
        } catch (\Exception $e) {
            // ignore
        }

        try {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropIndex(['sale_id']);
                $table->dropIndex(['product_id']);
            });
        } catch (\Exception $e) {
            // ignore
        }

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['status']);
            });
        } catch (\Exception $e) {
            // ignore
        }
    }
};
