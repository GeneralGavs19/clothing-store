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
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'batch_type')) {
                $table->string('batch_type', 32)->default('standard');
            }
            if (! Schema::hasColumn('products', 'batch_note')) {
                $table->string('batch_note', 255)->nullable();
            }
            if (! Schema::hasColumn('products', 'batch_period')) {
                $table->string('batch_period', 100)->nullable();
            }
            if (! Schema::hasColumn('products', 'transferred_at')) {
                $table->dateTime('transferred_at')->nullable();
            }
            if (! Schema::hasColumn('products', 'transferred_by')) {
                $table->unsignedBigInteger('transferred_by')->nullable();
            }
            if (! Schema::hasColumn('products', 'merged_into_id')) {
                $table->unsignedBigInteger('merged_into_id')->nullable();
            }
        });

        // Initialize default batch_type for existing records
        DB::table('products')->whereNull('batch_type')->orWhere('batch_type', '')->update([
            'batch_type' => 'standard',
        ]);

        // Detect and isolate products belonging exclusively to 'Новый завоз' based on cashier notes and sales
        $newBatchPureProductIds = [151, 156, 158, 161, 163, 164];

        foreach ($newBatchPureProductIds as $id) {
            $notes = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sale_items.product_id', $id)
                ->whereNotNull('sales.cashier_note')
                ->distinct()
                ->pluck('sales.cashier_note')
                ->implode(', ');

            $dates = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sale_items.product_id', $id)
                ->pluck('sales.approved_at');

            $minDate = $dates->min();
            $maxDate = $dates->max();
            $periodStr = null;
            if ($minDate && $maxDate) {
                $d1 = date('d.m.Y', strtotime($minDate));
                $d2 = date('d.m.Y', strtotime($maxDate));
                $periodStr = ($d1 === $d2) ? $d1 : "{$d1} — {$d2}";
            }

            DB::table('products')->where('id', $id)->update([
                'batch_type' => 'new_batch',
                'batch_note' => $notes ?: 'Новый завоз',
                'batch_period' => $periodStr ?: 'Октябрь 2026',
            ]);
        }

        // Add index on batch_type if not exists
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_products_batch_type ON products(batch_type)');
        } catch (\Throwable $e) {
            // Already exists or unsupported
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $cols = ['batch_type', 'batch_note', 'batch_period', 'transferred_at', 'transferred_by', 'merged_into_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
