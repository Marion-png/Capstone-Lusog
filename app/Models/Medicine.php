<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medicine extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'off_catalogue',
        'off_catalogue_reason',
        'stock_quantity',
        'minimum_threshold',
        'unit',
        'notes',
    ];

    protected $casts = [
        'off_catalogue' => 'boolean',
    ];
}
