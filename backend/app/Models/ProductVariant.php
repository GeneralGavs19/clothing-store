<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'size',
        'color',
        'sku',
        'barcode',
        'stock_warehouse',
        'stock_store',
        'low_stock_threshold',
        'sale_price',
        'purchase_price',
        'position',
    ];

    protected $casts = [
        'stock_warehouse' => 'integer',
        'stock_store' => 'integer',
        'low_stock_threshold' => 'integer',
        'sale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductItem::class);
    }

    public function availableItems(): HasMany
    {
        return $this->items()->where('status', ProductItem::STATUS_AVAILABLE);
    }

    public function stockTotal(): int
    {
        return (int) $this->stock_warehouse + (int) $this->stock_store;
    }

    public function isLowStock(): bool
    {
        $t = $this->stockTotal();

        return $t > 0 && $t <= (int) $this->low_stock_threshold;
    }
}
