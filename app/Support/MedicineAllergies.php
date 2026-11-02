<?php

namespace App\Support;

use App\Models\LearnerMedicineAllergy;
use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * A learner's medicine allergies as RxNorm concepts.
 *
 * The allergies themselves stay where they are typed — Sheet 1, the
 * Sulat-Pahibalo, a returned paper consent, the health assessment — and are
 * read through LearnerAllergies. This keeps one `learner_medicine_allergies`
 * row per medicine named there, with what RxNorm matched it to:
 *
 *  - sync() runs after a save that can change an allergy (and from the retry
 *    job): a new name is looked up once, a removed one is deleted;
 *  - forCheck() is what the dispense check reads. It never calls RxNav, and a
 *    recorded name with no matched row — not looked up yet, not found, RxNav
 *    down — is read as unverified, never as clear.
 *
 * A food allergy is not a medicine allergy and is left out. A Sheet 1 or
 * health assessment allergy whose kind nobody gave is looked up anyway: if it
 * names a medicine it matches, and if not it stays unverified.
 */
class MedicineAllergies
{
    /** What an allergy recorded with no name reads as in a message. */
    private const UNNAMED = 'an allergy recorded without a name';

    /** Look the learner's allergies up once the response has been sent. */
    public static function syncLater(?int $institutionId, ?string $lrn, string $by = ''): void
    {
        $lrn = trim((string) $lrn);

        if (! $institutionId || $lrn === '' || ! RxNormService::supported()) {
            return;
        }

        defer(fn () => self::sync($institutionId, $lrn, $by), 'rxnorm-allergies-'.$institutionId.'-'.$lrn);
    }

    /** Bring the learner's rows in step with what is recorded. */
    public static function sync(int $institutionId, string $lrn, string $by = ''): void
    {
        $wanted = self::wanted($institutionId, $lrn);

        foreach (self::rows($institutionId, $lrn) as $row) {
            $key = RxNormService::key((string) $row->entered_text);

            if (isset($wanted[$key])) {
                unset($wanted[$key]);

                continue;
            }

            $row->delete();
        }

        $rx = app(RxNormService::class);

        foreach ($wanted as $allergy) {
            $result = $rx->lookup($allergy['text']);

            LearnerMedicineAllergy::create([
                'institution_id' => $institutionId,
                'student_lrn' => $lrn,
                'entered_text' => $allergy['text'],
                'rx_concept_id' => $result['concept']?->id,
                // An allergy is never matched on a guess: candidates nobody
                // chose from are not a match.
                'match_status' => $result['status'] === RxNormService::CANDIDATES ? RxNormService::NOT_FOUND : $result['status'],
                'source' => $allergy['source'],
                'created_by_name' => $by !== '' ? $by : null,
            ]);
        }
    }

    /** Ask RxNav again for one row that could not be verified (the retry job). */
    public static function retry(LearnerMedicineAllergy $row): void
    {
        $result = app(RxNormService::class)->lookup((string) $row->entered_text);

        $row->update([
            'rx_concept_id' => $result['concept']?->id,
            'match_status' => $result['status'] === RxNormService::CANDIDATES ? RxNormService::NOT_FOUND : $result['status'],
        ]);
    }

    /**
     * The learner's medicine allergies for the dispense check, from the
     * database only. NULL when the LRN names nobody at this school.
     *
     * @return list<array{id: ?int, text: string, matched: bool, ingredients: array<string, string>, atc3: list<string>}>|null
     */
    public static function forCheck(?int $institutionId, string $lrn): ?array
    {
        $lrn = trim($lrn);

        if (! $institutionId || $lrn === '' || ! StudentHealthRecord::query()
            ->where('institution_id', $institutionId)->where('student_id', $lrn)->exists()) {
            return null;
        }

        $rows = self::rows($institutionId, $lrn, withConcepts: true)
            ->keyBy(fn (LearnerMedicineAllergy $row): string => RxNormService::key((string) $row->entered_text));

        $entries = [];
        foreach (LearnerAllergies::for($institutionId, $lrn) as $allergy) {
            if ($allergy['kind'] === LearnerAllergies::KIND_FOOD) {
                continue;
            }

            if ($allergy['text'] === LearnerAllergies::NOT_SPECIFIED) {
                $entries[] = ['id' => null, 'text' => self::UNNAMED, 'matched' => false, 'ingredients' => [], 'atc3' => []];

                continue;
            }

            foreach (self::terms($allergy['text']) as $term) {
                $row = $rows->get(RxNormService::key($term));
                $matched = $row !== null && $row->match_status === RxNormService::MATCHED && $row->concept !== null;

                $entries[] = [
                    'id' => $row?->id,
                    'text' => $term,
                    'matched' => $matched,
                    'ingredients' => $matched ? $row->concept->ingredientMap() : [],
                    'atc3' => $matched ? $row->concept->atcLevel3() : [],
                ];
            }
        }

        return $entries;
    }

    /**
     * The allergy list the consultation dialog shows, each medicine allergy
     * marked `verified` (false is the "Allergy not verified" badge; a food
     * allergy is not checked and carries null).
     *
     * @param  list<array{kind: string, text: string, sources: list<string>, source: string}>  $allergies
     * @return list<array<string, mixed>>
     */
    public static function annotate(array $allergies, ?int $institutionId, string $lrn): array
    {
        if (! $institutionId || ! RxNormService::supported()) {
            return $allergies;
        }

        $matched = self::rows($institutionId, trim($lrn))
            ->filter(fn (LearnerMedicineAllergy $row): bool => $row->match_status === RxNormService::MATCHED)
            ->map(fn (LearnerMedicineAllergy $row): string => RxNormService::key((string) $row->entered_text))
            ->flip();

        return array_map(function (array $allergy) use ($matched): array {
            if ($allergy['kind'] === LearnerAllergies::KIND_FOOD) {
                return $allergy + ['verified' => null];
            }

            $terms = $allergy['text'] === LearnerAllergies::NOT_SPECIFIED ? [] : self::terms($allergy['text']);

            return $allergy + ['verified' => $terms !== [] && collect($terms)
                ->every(fn (string $term): bool => $matched->has(RxNormService::key($term)))];
        }, $allergies);
    }

    /**
     * One recorded line split into the medicines it names:
     * "Ibuprofen, amoxicillin and cotrimoxazole" is three.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $text = (string) preg_replace('/\([^)]*\)/u', ' ', $text);
        $parts = preg_split('/\s*(?:[,;\n]|&|\band\b)\s*/iu', $text) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            $part = (string) preg_replace('/^allerg(?:y|ic)\s+(?:to|sa)\s+/iu', '', trim($part));
            $part = trim($part, " \t.-");

            if ($part !== '' && ConsentFormScanner::saysSomething($part)) {
                $terms[] = $part;
            }
        }

        return $terms;
    }

    /** @return array<string, array{text: string, source: string}> keyed by RxNormService::key() */
    private static function wanted(int $institutionId, string $lrn): array
    {
        $wanted = [];

        foreach (LearnerAllergies::for($institutionId, $lrn) as $allergy) {
            if ($allergy['kind'] === LearnerAllergies::KIND_FOOD || $allergy['text'] === LearnerAllergies::NOT_SPECIFIED) {
                continue;
            }

            foreach (self::terms($allergy['text']) as $term) {
                $wanted[RxNormService::key($term)] ??= ['text' => $term, 'source' => $allergy['source']];
            }
        }

        return $wanted;
    }

    /** @return Collection<int, LearnerMedicineAllergy> */
    private static function rows(int $institutionId, string $lrn, bool $withConcepts = false): Collection
    {
        return LearnerMedicineAllergy::query()
            ->when($withConcepts, fn ($query) => $query->with('concept.ingredients'))
            ->where('institution_id', $institutionId)
            ->where('student_lrn', $lrn)
            ->orderBy('id')
            ->get();
    }
}
