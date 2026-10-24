<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'type',
        'name',
        'brand',
        'color',
        'photo_path',
        'description',
        'purchase_price',
        'base_sale_price',
        'sale_price',
        'stock_quantity',
        'display_quantity',
        'low_stock_threshold',
        'status',
        'created_by',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'base_sale_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'display_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
    ];

    protected $appends = ['total_quantity', 'total_sold', 'initial_quantity', 'photo_url', 'size'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->stock_quantity + (int) $this->display_quantity;
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
            ->where('product_id', $this->id)
            ->whereHas('sale', fn ($q) => $q->where('status', 'approved'))
            ->sum('quantity');
    }

    public function getInitialQuantityAttribute(): int
    {
        return (int) $this->total_quantity + (int) $this->total_sold;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::url($this->photo_path) : null;
    }

    /** Краткое перечисление размеров вариантов для подписи в списке. */
    public function getSizeAttribute(): ?string
    {
        if ($this->relationLoaded('variants')) {
            $sizes = $this->variants->pluck('size')->filter()->unique()->values()->take(8);

            return $sizes->isEmpty() ? null : $sizes->implode(', ');
        }

        return ProductVariant::query()
            ->where('product_id', $this->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('size')
            ->filter()
            ->unique()
            ->take(8)
            ->implode(', ') ?: null;
    }

    public function applyAggregatesFromVariants(): void
    {
        $variants = ProductVariant::query()
            ->where('product_id', $this->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            $this->stock_quantity = 0;
            $this->display_quantity = 0;
            $this->sale_price = (float) ($this->base_sale_price ?? 0);
            $this->status = 'out_of_stock';

            return;
        }

        $this->stock_quantity = (int) $variants->sum('stock_warehouse');
        $this->display_quantity = (int) $variants->sum('stock_store');

        if ($this->sale_price === null || (float) $this->sale_price <= 0) {
            $this->sale_price = (float) ($this->base_sale_price ?? 0);
        }

        $positiveThresholds = $variants->map(fn (ProductVariant $v) => (int) $v->low_stock_threshold)->filter(fn ($x) => $x >= 0);
        $this->low_stock_threshold = $positiveThresholds->isEmpty()
            ? 0
            : (int) $positiveThresholds->min();

        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $total = (int) $this->stock_quantity + (int) $this->display_quantity;

        if ($total <= 0) {
            $this->status = 'out_of_stock';

            return;
        }

        $anyLowVariant = ProductVariant::query()
            ->where('product_id', $this->id)
            ->whereRaw('(stock_warehouse + stock_store) <= low_stock_threshold')
            ->whereRaw('(stock_warehouse + stock_store) > 0')
            ->exists();

        $this->status = $anyLowVariant ? 'low_stock' : 'active';
    }
}
