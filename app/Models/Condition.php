<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Condition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'created_by',
    ];

    /**
     * Scope to search conditions by name (case-insensitive).
     */
    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%']);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory($query, ?string $category)
    {
        if (! $category) {
            return $query;
        }

        return $query->where('category', $category);
    }
}
