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
        'product_name',
        'product_sku',
        'quantity',
        'source_location',
        'purchase_price',
        'sale_price',
        'line_total',
        'line_profit',
    ];

    protected $appends = ['display_name'];

    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
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

    public function getDisplayNameAttribute(): string
    {
        return $this->product_name ?: $this->product?->name ?: 'Удалённый товар';
    }
}
