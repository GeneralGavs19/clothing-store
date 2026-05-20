<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $demoSkus = [
            'SAM-GAL-001',
            'HP-PAV-001',
            'AUD-BT-001',
            'CLO-TSH-001',
            'CLO-JEA-001',
            'FOD-COF-001',
            'FOD-TEA-001',
            'FOD-CHO-001',
        ];

        DB::table('products')->whereIn('sku', $demoSkus)->delete();

        $demoCategoryNames = ['Электроника', 'Одежда', 'Продукты питания'];
        $unusedCategoryIds = DB::table('categories')
            ->whereIn('name', $demoCategoryNames)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.category_id', 'categories.id');
            })
            ->pluck('id');

        if ($unusedCategoryIds->isNotEmpty()) {
            DB::table('categories')->whereIn('id', $unusedCategoryIds->all())->delete();
        }
    }

    public function down(): void
    {
        // Nothing to rollback: demo data should stay removed.
    }
};
