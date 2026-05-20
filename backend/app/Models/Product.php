<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'barcode',
        'size',
        'variants',
        'photo_path',
        'description',
        'purchase_price',
        'sale_price',
        'stock_quantity',
        'display_quantity',
        'low_stock_threshold',
        'status',
        'created_by',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'display_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'variants' => 'array',
    ];

    protected $appends = ['total_quantity', 'photo_url'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->stock_quantity + (int) $this->display_quantity;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::url($this->photo_path) : null;
    }

    public function refreshStatus(): void
    {
        $total = $this->total_quantity;
        $this->status = match (true) {
            $total === 0 => 'out_of_stock',
            $total <= $this->low_stock_threshold => 'low_stock',
            default => 'active',
        };
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (!$product->barcode) {
                $product->barcode = self::generateUniqueBarcode();
            }
        });
    }

    private static function generateUniqueBarcode(): string
    {
        do {
            $barcode = '28'.str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT);
        } while (self::query()->where('barcode', $barcode)->exists());

        return $barcode;
    }
}
