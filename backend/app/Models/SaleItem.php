<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'product_variant_id',
        'product_item_id',
        'product_item_code',
        'product_name',
        'product_type',
        'product_sku',
        'quantity',
        'is_on_order',
        'source_location',
        'variant_size',
        'purchase_price',
        'original_price',
        'discount_percent',
        'discount_amount',
        'sale_price',
        'line_total',
        'line_profit',
    ];

    protected $appends = ['display_name'];

    protected $casts = [
        'quantity' => 'integer',
        'is_on_order' => 'boolean',
        'purchase_price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_profit' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function productItem()
    {
        return $this->belongsTo(ProductItem::class);
    }

    public function getDisplayNameAttribute(): string
    {
        $base = $this->product_name ?: $this->product?->name ?: 'Удалённый товар';
        if ($this->product_item_code) {
            return $base.' · '.$this->variant_size.' · '.$this->product_item_code;
        }
        if ($this->variant_size) {
            return $base.' · '.$this->variant_size;
        }

        return $base;
    }
}
