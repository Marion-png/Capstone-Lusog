<?php

namespace App\Support;

use App\Models\Consultation;

/**
 * Who may see what a learner came to the clinic for.
 *
 * A consultation carries the complaint, the diagnosis and the treatment — the
 * clinical narrative about a child. Only the people treating them need it, so
 * only they see it:
 *
 *   - School nurse and clinic staff: the whole record.
 *   - Class adviser: the date, the time, and that the learner attended the
 *     clinic. Nothing about why. A teacher needs to know a pupil was out of
 *     class and can ask the clinic if there is something they must act on;
 *     they do not need the diagnosis to do their job, and the minimum
 *     necessary principle says they should not be handed it.
 *   - School head: nothing per-visit at all. Their screens are management
 *     summaries, and a principal reading one has no need of a named child's
 *     complaint. School-wide tallies stay — "twelve headaches this month" is
 *     a statistic about the school, not a detail about a learner.
 *
 * This class is the single place that decides, so a panel added later cannot
 * quietly widen the audience by rendering a field somebody forgot to strip.
 * The redaction happens where the payload is built, never in the template: a
 * value that reaches the browser has been disclosed whether or not a view
 * chose to print it.
 */
class ConsultationVisibility
{
    /**
     * The desks that treat the learner, and therefore read the record.
     *
     * @var list<string>
     */
    public const DETAIL_ROLES = ['school_nurse', 'clinic_staff'];

    /** Shown in place of a redacted clinical field. */
    public const REDACTED_LABEL = 'Recorded by the clinic';

    public static function maySeeDetails(?string $role): bool
    {
        return in_array(strtolower(trim((string) $role)), self::DETAIL_ROLES, true);
    }

    /**
     * One visit, as the given role may see it.
     *
     * @return array<string, mixed>
     */
    public static function present(Consultation $consultation, ?string $role): array
    {
        // The visit itself — that it happened, and when. Never redacted: this
        // is the part a class adviser is entitled to.
        $visit = [
            'consulted_at' => $consultation->consulted_at?->toDateTimeString(),
            'date' => $consultation->consulted_at?->format('M j, Y'),
            'time' => $consultation->consulted_at?->format('g:i A'),
            'consulted_at_label' => $consultation->consulted_at?->format('M j, Y \a\t g:i A'),
            'details_visible' => self::maySeeDetails($role),
        ];

        if (! self::maySeeDetails($role)) {
            return $visit;
        }

        return $visit + [
            'grade_section' => (string) $consultation->grade_section,
            'condition' => (string) $consultation->condition,
            'treatment_given' => (string) $consultation->treatment_given,
            'status' => (string) $consultation->status,
        ];
    }

    /**
     * A learner's visits, as the given role may see them.
     *
     * @param  iterable<Consultation>  $consultations
     * @return list<array<string, mixed>>
     */
    public static function presentMany(iterable $consultations, ?string $role): array
    {
        $rows = [];

        foreach ($consultations as $consultation) {
            $rows[] = self::present($consultation, $role);
        }

        return $rows;
    }
}
