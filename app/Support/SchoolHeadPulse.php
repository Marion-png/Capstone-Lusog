<?php

namespace App\Support;

/**
 * Change detection for every School Head screen.
 *
 * The head reads what other roles write: a class adviser encodes a learner and
 * their weighing, a school nurse logs a consultation or a clinic note, the
 * coordinator marks a feeding day, the clinic moves stock. None of that happens
 * on the head's screen, so the screen has to notice it — the Dashboard by
 * re-rendering its panels, the other tabs by reloading themselves.
 *
 * The stamp is a fingerprint of row counts and last-touched timestamps across
 * the tables those roles write to. It carries **no personal information**, so a
 * page can poll it on a timer and pay for the (much heavier) rebuild only when
 * it actually moves — which is why the pulse route is exempt in
 * AuditSensitiveAccess.
 *
 * It is scoped to one school. A neighbouring school's write never moves this
 * school's stamp on a table that carries `institution_id`, and never reaches
 * this school's figures at all — the reads themselves are scoped, and
 * SchoolHeadOverview / SchoolHeadHealthOverview return nothing without an
 * institution rather than falling through to every school's children.
 *
 * The audit trail is deliberately not watched: every authenticated page view
 * writes to it, so a stamp that included it would report a change on every poll
 * and a page would rebuild itself forever.
 */
final class SchoolHeadPulse
{
    /**
     * Tables whose rows can move any figure the head reads, and the role that
     * writes to each:
     *
     *   student_health_records  class adviser (roster, baseline, endline)
     *   parental_consent_forms  class adviser (deworming consent)
     *   health_consent_forms    class adviser / parent (health services consent)
     *   health_assessments      school nurse (screening)
     *   feeding_attendances     feeding coordinator (marks)
     *   attendance_imports      feeding coordinator (sheets)
     *   consultations           school nurse / clinic staff (clinic visits)
     *   clinic_notes            school nurse (observations)
     *   medicines               clinic (stock levels)
     *   medicine_dispenses      school nurse (issues)
     */
    public const WATCHED_TABLES = [
        'student_health_records',
        'parental_consent_forms',
        'health_consent_forms',
        'health_assessments',
        'feeding_attendances',
        'attendance_imports',
        'consultations',
        'clinic_notes',
        'medicines',
        'medicine_dispenses',
    ];

    public static function stamp(?int $institutionId): string
    {
        // One round trip for all ten tables. This is polled every twenty
        // seconds by every open School Head tab, so its cost is paid over and
        // over; a query per table made a "cheap" poll the most expensive thing
        // the role did.
        return ChangeStamp::forTables(self::WATCHED_TABLES, $institutionId);
    }
}
