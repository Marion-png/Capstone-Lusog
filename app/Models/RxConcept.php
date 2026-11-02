<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One RxNorm concept as RxNav described it (App\Support\RxNormService), shared
 * by every medicine and allergy that matched it. Not personal data.
 *
 * `atc_codes` holds the ATC classes of the concept's ingredients as
 * [{code, name}]; the allergy check compares their first four characters
 * (ATC level 3).
 */
class RxConcept extends Model
{
    protected $fillable = ['rxcui', 'name', 'tty', 'atc_codes', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'atc_codes' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RxConceptIngredient::class);
    }

    /**
     * Ingredient RxCUI => name.
     *
     * @return array<string, string>
     */
    public function ingredientMap(): array
    {
        return $this->ingredients
            ->mapWithKeys(fn (RxConceptIngredient $ingredient): array => [$ingredient->ingredient_rxcui => $ingredient->ingredient_name])
            ->all();
    }

    /**
     * ATC level-3 codes (the first four characters), e.g. M01A.
     *
     * @return list<string>
     */
    public function atcLevel3(): array
    {
        return collect($this->atc_codes ?? [])
            ->map(fn ($class): string => strtoupper(substr((string) ($class['code'] ?? ''), 0, 4)))
            ->filter(fn (string $code): bool => strlen($code) === 4)
            ->unique()
            ->values()
            ->all();
    }
}
