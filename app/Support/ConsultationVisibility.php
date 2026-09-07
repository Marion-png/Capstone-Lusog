<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\ConsultationPhoto;

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
 *
 * ---------------------------------------------------------------------------
 * CONTESTED — the class adviser's line is NOT settled. See
 * docs/open-decisions.md, entry 1.
 *
 * What is written above is Ma'am Nanette's guidance (minimum necessary: a
 * teacher needs to know a pupil was out of class, not why). The school nurse
 * disagrees on the record: she argues the adviser is the "second parent sa
 * school", holds the direct line to the parents, and cannot inform them
 * properly without the detail.
 *
 * Both positions are coherent and they are incompatible. This file implements
 * one of them because the code had to do something; that is a default, not a
 * ruling. It is Ma'am Nanette's to decide.
 *
 * If it goes the nurse's way the change is one line — add 'class_adviser' to
 * DETAIL_ROLES — plus the assertions in ConsultationPrivacyTest that currently
 * pin the redaction. Do not make that change on your own judgement, and do not
 * widen the School Head at the same time: the parent-contact argument does not
 * apply to a principal.
 * ---------------------------------------------------------------------------
 */
class ConsultationVisibility
{
    /**
     * The desks that treat the learner, and therefore read the record.
     *
     * CONTESTED: whether 'class_adviser' belongs here is an open question for
     * Ma'am Nanette — docs/open-decisions.md, entry 1. Do not add it without
     * her ruling.
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
        //
        // The id rides along because it is not clinical: it is what the photo
        // endpoints are keyed by, and the adviser's tab needs it to fetch the
        // photographs the nurse deliberately shared. Those endpoints do their
        // own checking — an id here grants nothing on its own.
        $visit = [
            'id' => $consultation->id,
            'consulted_at' => $consultation->consulted_at?->toDateTimeString(),
            'date' => $consultation->consulted_at?->format('M j, Y'),
            'time' => $consultation->consulted_at?->format('g:i A'),
            'consulted_at_label' => $consultation->consulted_at?->format('M j, Y \a\t g:i A'),
            'details_visible' => self::maySeeDetails($role),
            'shared_photo_count' => self::sharedPhotoCount($consultation),
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
     * How many photographs on this visit the nurse shared with the adviser.
     *
     * Zero for a visit with none, and zero before the table exists — a count
     * is not clinical detail, it is what tells the adviser's tab whether to
     * offer anything at all.
     */
    public static function sharedPhotoCount(Consultation $consultation): int
    {
        if (! SchemaCache::hasTable('consultation_photos')) {
            return 0;
        }

        return ConsultationPhoto::query()
            ->where('consultation_id', $consultation->id)
            ->sharedWithAdviser()
            ->count();
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
