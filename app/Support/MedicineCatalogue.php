<?php

namespace App\Support;

/**
 * The DepEd-allowed medicine list, and the one rule about going outside it.
 *
 * Read the catalogue through this class, never straight from config, or the
 * Add Medicine form and whatever checks it will drift apart — which is the
 * whole failure the dropdown exists to prevent. One school typing
 * "Paracetamol 500mg" and another "paracetamol 500 mg" produces two items
 * nobody can total.
 *
 * Going outside the list is allowed on purpose. The school nurse stated
 * plainly that she dispenses beyond it when clinically necessary —
 * antibiotics, mefenamic acid, allergy medicine — and a system that refuses
 * to record that does not stop it happening, it only stops the record being
 * true. An off-list medicine kept in a notebook cannot be counted, reordered
 * or answered for. So the form takes one, marks it, and asks why.
 */
class MedicineCatalogue
{
    /** What the form shows for "not on the list". */
    public const OTHER = '__other__';

    /**
     * The catalogue, grouped as a nurse looks for it.
     *
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = config('medicines.catalogue', []);

        return $groups;
    }

    /**
     * Every allowed name, flat.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::grouped() as $items) {
            foreach ($items as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function has(string $name): bool
    {
        return in_array(trim($name), self::names(), true);
    }

    /** Whether this school may stock something the list does not carry. */
    public static function allowsOffCatalogue(): bool
    {
        return (bool) config('medicines.allow_off_catalogue', true);
    }

    /**
     * Resolve what the form posted into the name to store, and whether it sits
     * outside the list.
     *
     * A catalogue pick is trusted; anything else is off-list and is recorded
     * as such, so the school can see at a glance what it is holding beyond
     * what DepEd supplies.
     *
     * @return array{name: string, off_catalogue: bool}
     */
    public static function resolve(?string $choice, ?string $custom): array
    {
        $choice = trim((string) $choice);
        $custom = trim((string) $custom);

        if ($choice !== '' && $choice !== self::OTHER && self::has($choice)) {
            return ['name' => $choice, 'off_catalogue' => false];
        }

        return ['name' => $custom, 'off_catalogue' => true];
    }
}
