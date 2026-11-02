<?php

namespace App\Support;

use App\Models\DispenseAllergyCheck;
use App\Models\Medicine;

/**
 * The allergy check on a dispense — decision support, never the decision.
 *
 * Compares the medicine's RxNorm ingredients and ATC level-3 classes with the
 * learner's medicine allergies, from the database only (MedicineAllergies),
 * and answers one of five results:
 *
 *  - School Nurse: an allergy to an ingredient of the medicine is a WARNING
 *    (saved only with an override reason of 10 characters or more), an
 *    allergy in the same ATC level-3 class a CAUTION (acknowledged, then
 *    saved), and anything not verified UNVERIFIED (acknowledged, then saved).
 *    The strongest match wins.
 *  - Clinic Teacher and Clinic Staff (paracetamol only, DispensingRights):
 *    any recorded paracetamol allergy, matched or only named in the text, is
 *    BLOCKED with no override; otherwise UNVERIFIED or CLEAR as for the nurse.
 *
 * An unverified allergy is never read as clear. A medicine nobody has matched
 * matters only when the learner has a medicine allergy to compare it with.
 * The same evaluate() answers the dialog before the save and the controller
 * at the save, and record() writes every result, clear ones included.
 */
class AllergyCheck
{
    public const CLEAR = 'clear';

    public const CAUTION = 'caution';

    public const WARNING = 'warning';

    public const BLOCKED = 'blocked';

    public const UNVERIFIED = 'unverified';

    /** Shown with every result. */
    public const NOTE = 'Decision support only. The School Nurse decides.';

    /** The shortest override reason accepted. */
    public const OVERRIDE_MIN = 10;

    /** Words that name paracetamol in an allergy RxNorm could not match. */
    private const PARACETAMOL_WORDS = ['paracetamol', 'acetaminophen', 'biogesic'];

    /** Whether the check can run on this database. */
    public static function supported(): bool
    {
        return RxNormService::supported();
    }

    /**
     * @return array{result: string, message: string, allergy_id: ?int}
     */
    public static function evaluate(?string $role, Medicine $medicine, ?int $institutionId, ?string $lrn): array
    {
        $allergies = MedicineAllergies::forCheck($institutionId, (string) $lrn);

        if ($allergies === null) {
            return self::answer(self::UNVERIFIED, 'No learner was chosen from the roll, so no recorded allergy could be checked. Check the learner\'s allergies manually.');
        }

        $medicine->loadMissing('rxConcept.ingredients');
        $name = (string) $medicine->name;

        if (DispensingRights::isRestricted($role)) {
            foreach ($allergies as $allergy) {
                $paracetamol = $allergy['matched']
                    ? in_array('acetaminophen', array_map('mb_strtolower', $allergy['ingredients']), true)
                    : self::namesParacetamol($allergy['text']);

                if ($paracetamol) {
                    return self::answer(self::BLOCKED, 'Learner has a recorded paracetamol allergy. Refer to the School Nurse.', $allergy['id']);
                }
            }

            return self::unverifiedOrClear($allergies, $medicine);
        }

        if ($medicine->isRxMatched()) {
            $ingredients = $medicine->rxConcept->ingredientMap();
            $classes = $medicine->rxConcept->atcLevel3();

            foreach ($allergies as $allergy) {
                $shared = array_intersect_key($ingredients, $allergy['ingredients']);

                if ($shared !== []) {
                    return self::answer(self::WARNING, 'Learner is allergic to '.$allergy['text'].'. '.$name.' contains '.reset($shared).'.', $allergy['id']);
                }
            }

            foreach ($allergies as $allergy) {
                $class = array_values(array_intersect($classes, $allergy['atc3']));

                if ($class !== []) {
                    return self::answer(self::CAUTION, $name.' is in the same drug class as '.$allergy['text'].' (ATC '.$class[0].'). Check before giving.', $allergy['id']);
                }
            }
        }

        return self::unverifiedOrClear($allergies, $medicine);
    }

    /** What the save needs before it may go ahead: none, acknowledge, override, or blocked. */
    public static function requires(string $result): string
    {
        return match ($result) {
            self::BLOCKED => 'blocked',
            self::WARNING => 'override',
            self::CAUTION, self::UNVERIFIED => 'acknowledge',
            default => 'none',
        };
    }

    /**
     * Why the save may not go ahead, as [field => message], or [] when it may.
     * A blocked result has no override, and only the nurse sees a warning.
     *
     * @return array<string, string>
     */
    public static function refusal(array $check, ?string $overrideReason, bool $acknowledged): array
    {
        return match (self::requires($check['result'])) {
            'blocked' => ['medicine_id' => $check['message']],
            'override' => mb_strlen(trim((string) $overrideReason)) >= self::OVERRIDE_MIN
                ? []
                : ['allergy_override_reason' => 'Give a reason of at least '.self::OVERRIDE_MIN.' characters to override the allergy warning.'],
            'acknowledge' => $acknowledged
                ? []
                : ['allergy_acknowledged' => 'Confirm that you have checked the learner\'s allergies.'],
            default => [],
        };
    }

    /** Write the check, whatever it found. */
    public static function record(array $check, ?int $institutionId, Medicine $medicine, ?string $lrn, ?int $consultationId, string $role, string $by, ?string $overrideReason = null): DispenseAllergyCheck
    {
        $overridden = $check['result'] === self::WARNING && trim((string) $overrideReason) !== '';

        return DispenseAllergyCheck::create([
            'institution_id' => $institutionId,
            'consultation_id' => $consultationId,
            'medicine_id' => $medicine->id,
            'student_lrn' => trim((string) $lrn) !== '' ? trim((string) $lrn) : null,
            'result' => $check['result'],
            'learner_medicine_allergy_id' => $check['allergy_id'],
            'overridden' => $overridden,
            'override_reason' => $overridden ? trim((string) $overrideReason) : null,
            'checked_by_name' => $by !== '' ? $by : null,
            'checked_by_role' => $role,
            'checked_at' => now(),
        ]);
    }

    /** @param  list<array{id: ?int, text: string, matched: bool}>  $allergies */
    private static function unverifiedOrClear(array $allergies, Medicine $medicine): array
    {
        foreach ($allergies as $allergy) {
            if (! $allergy['matched']) {
                return self::answer(self::UNVERIFIED, self::couldNotCheck($allergy['text']), $allergy['id']);
            }
        }

        if ($allergies !== [] && ! $medicine->isRxMatched()) {
            return self::answer(self::UNVERIFIED, self::couldNotCheck((string) $medicine->name));
        }

        return self::answer(self::CLEAR, 'No recorded medicine allergy matches.');
    }

    private static function couldNotCheck(string $name): string
    {
        return 'Allergy check could not be completed for '.$name.'. Check the learner\'s allergies manually.';
    }

    private static function namesParacetamol(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach (self::PARACETAMOL_WORDS as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    private static function answer(string $result, string $message, ?int $allergyId = null): array
    {
        return ['result' => $result, 'message' => $message, 'allergy_id' => $allergyId];
    }
}
