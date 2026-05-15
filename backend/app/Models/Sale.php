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

    protected $casts = [
        'subtotal' => 'decimal:2',
        'profit' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

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
