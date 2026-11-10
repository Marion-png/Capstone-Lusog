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

    /**
     * Words that name a clinic supply rather than a drug, for an item typed
     * outside the list. Whole words only, so a medicine whose name merely
     * contains one is not caught.
     */
    public const SUPPLY_WORDS = [
        'gauze', 'bandage', 'bandages', 'cotton', 'tape', 'glove', 'gloves', 'mask', 'masks',
        'syringe', 'syringes', 'tongue depressor', 'probe cover', 'ice pack', 'cold pack',
    ];

    /**
     * Whether an item is a supply, not a medicine: on the list's supplies, or
     * named as one. RxNorm has nothing to say about a roll of tape, so a
     * supply is never looked up and its status reads "Not applicable".
     *
     * A drug read as a supply loses nothing at the dispense: the allergy check
     * treats any item it could not match as one to check by hand.
     */
    public static function isSupply(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $supplies = array_map('mb_strtolower', (array) config('medicines.supplies', []));
        if (in_array(mb_strtolower($name), $supplies, true)) {
            return true;
        }

        if (self::has($name)) {
            return false;
        }

        $words = ' '.preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name)).' ';

        foreach (self::SUPPLY_WORDS as $word) {
            if (str_contains($words, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The generic the list gives an item, where its name does not say it, or
     * null to let the form read it off the name.
     */
    public static function genericFor(string $name): ?string
    {
        $generic = ((array) config('medicines.generic_names', []))[trim($name)] ?? null;

        return is_string($generic) && trim($generic) !== '' ? trim($generic) : null;
    }

    /**
     * For an item named by its class ("Antifungal cream"), the hint the form
     * shows in place of a guessed generic; null for an item whose name says it.
     */
    public static function labelHint(string $name): ?string
    {
        $entry = ((array) config('medicines.label_generics', []))[trim($name)] ?? null;

        return is_array($entry) && trim((string) ($entry['hint'] ?? '')) !== '' ? trim((string) $entry['hint']) : null;
    }

    /**
     * Why a stored generic cannot be this item's, or null when it can (or
     * when nothing is known about the class): Povidone-iodine, an antiseptic,
     * saved as an antifungal cream's (testers' report #17).
     */
    public static function genericMismatch(string $name, ?string $generic): ?string
    {
        $entry = ((array) config('medicines.label_generics', []))[trim($name)] ?? null;
        $allowed = is_array($entry) ? array_map('strtolower', (array) ($entry['generics'] ?? [])) : [];
        $generic = strtolower(trim((string) $generic));

        if ($allowed === [] || $generic === '') {
            return null;
        }

        foreach ($allowed as $candidate) {
            if (str_contains($generic, $candidate)) {
                return null;
            }
        }

        return '"'.trim($name).'" has "'.$generic.'" as its generic name, which is not one this kind of item can be ('.implode(', ', $allowed).'). Check the product\'s label.';
    }

    /** The dosage forms a name may end in, singular, as compared. */
    private const FORMS = ['tablet', 'capsule', 'syrup', 'suspension', 'solution', 'cream', 'ointment', 'lotion', 'sachet', 'drop', 'gel', 'spray', 'powder'];

    /**
     * The generic an item's name implies, as the Add Medicine form fills it:
     * the list's own generic, else the name without its strength and form
     * ("Paracetamol 500mg tablet" → "Paracetamol").
     */
    public static function impliedGeneric(string $name): string
    {
        $listed = self::genericFor($name);
        if ($listed !== null) {
            return $listed;
        }

        $base = preg_split('/\s\d|\s\(/', trim($name))[0] ?? '';

        return trim((string) preg_replace('/\s+(tablets?|capsules?|syrup|suspension|solution|cream|lotion|sachets?|drops)\b.*$/i', '', $base));
    }

    /**
     * An item's strength and dosage form, compared without case or spacing
     * ("500mg|tablet"), or '' when its name says neither — then nothing can
     * be said to match it on them.
     */
    public static function strengthAndForm(string $name): string
    {
        $plain = mb_strtolower($name);

        preg_match_all('/(\d+(?:[.,]\d+)*)\s*(mcg|mg|g|ml|iu|%)(?:\s*\/\s*(\d+(?:\.\d+)?)\s*(ml))?/', $plain, $matches, PREG_SET_ORDER);
        $strength = implode('+', array_map(
            fn (array $m): string => str_replace(',', '', $m[1]).$m[2].(isset($m[3]) && $m[3] !== '' ? '/'.$m[3].($m[4] ?? '') : ''),
            $matches,
        ));

        $form = '';
        foreach (self::FORMS as $candidate) {
            if (preg_match('/\b'.$candidate.'s?\b/', $plain) === 1) {
                $form = $candidate;
                break;
            }
        }

        return $strength === '' && $form === '' ? '' : $strength.'|'.$form;
    }

    /**
     * The name an item is compared by: case, spacing and punctuation dropped,
     * so "Paracetamol 500 mg Tablet" and "paracetamol 500mg tablet" are found
     * to be one item.
     */
    public static function comparableName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($name)));
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
