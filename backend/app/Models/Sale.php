<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'cashier_id',
        'approved_by',
        'status',
        'subtotal',
        'profit',
        'cashier_note',
        'admin_note',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected $appends = ['display_title', 'sold_at'];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'profit' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function getSoldAtAttribute(): ?string
    {
        return ($this->approved_at ?? $this->created_at)?->toIso8601String();
    }

    public function getDisplayTitleAttribute(): string
    {
        $at = $this->approved_at ?? $this->created_at;
        $date = $at?->format('d.m.Y H:i') ?? '—';
        $qty = $this->relationLoaded('items')
            ? (int) $this->items->sum('quantity')
            : (int) $this->items()->sum('quantity');
        $amount = number_format((float) $this->subtotal, 0, '.', ' ');

        if ($this->number && $this->number !== 'draft' && ! str_starts_with($this->number, 'S-')) {
            return $this->number;
        }

        if ($qty > 0 && $this->subtotal > 0) {
            return sprintf('№%d · %s · %d шт. · %s ₸', $this->id, $date, $qty, $amount);
        }

        return "Продажа №{$this->id} · {$date}";
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function pendingSale()
    {
        return $this->hasOne(PendingSale::class);
    }
}
