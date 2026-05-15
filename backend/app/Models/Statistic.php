<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Statistic extends Model
{
    protected $fillable = ['date', 'metric', 'value', 'payload'];

    protected $casts = [
        'date' => 'date',
        'value' => 'decimal:2',
        'payload' => 'array',
    ];
}
