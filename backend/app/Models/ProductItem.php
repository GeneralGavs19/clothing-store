<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductItem extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_SOLD = 'sold';

    public const LOCATION_STOCK = 'stock';

    public const LOCATION_DISPLAY = 'display';

    protected $fillable = [
        'product_variant_id',
        'code',
        'status',
        'location',
        'sold_at',
        'sale_id',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
}
