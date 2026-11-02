<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One ingredient of an RxNorm concept (co-amoxiclav has two). */
class RxConceptIngredient extends Model
{
    protected $fillable = ['rx_concept_id', 'ingredient_rxcui', 'ingredient_name'];
}
