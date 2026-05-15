<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyStat extends Model
{
    use HasFactory;

    protected $table = 'daily_stats';

    protected $fillable = [
        'date',
        'total_sales',
        'revenue',
        'profit',
        'items_sold',
    ];

    protected $casts = [
        'date' => 'date',
        'total_sales' => 'integer',
        'revenue' => 'decimal:2',
        'profit' => 'decimal:2',
        'items_sold' => 'integer',
    ];
}
