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

    protected $appends = ['total_sold', 'initial_quantity'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function getTotalSoldAttribute(): int
    {
        if (array_key_exists('total_sold', $this->attributes)) {
            return (int) $this->attributes['total_sold'];
        }

        if ($this->relationLoaded('saleItems')) {
            return (int) $this->saleItems->sum('quantity');
        }

        return (int) SaleItem::query()
            ->where('product_variant_id', $this->id)
            ->whereHas('sale', fn ($q) => $q->where('status', 'approved'))
            ->sum('quantity');
    }

    public function getInitialQuantityAttribute(): int
    {
        return (int) $this->stockTotal() + (int) $this->total_sold;
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
