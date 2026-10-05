<?php

namespace App\Support;

/**
 * Change detection for every screen in one school.
 *
 * Nobody in this application works alone. A class adviser encodes a learner
 * and their weighing, uploads a class list, files a consent form; a school
 * nurse examines them and logs a consultation; the Feeding Coordinator marks a
 * feeding day; the clinic moves stock; the School Head reads all of it. None
 * of that happens on the screen of the person who needs to see it, so the
 * screen has to notice — otherwise "submitted" means "submitted, and now go
 * and tell them."
 *
 * This was `SchoolPulse` and watched the same tables, because the head was
 * the first role to need it. It was never actually the head's: the list is
 * **the school's own write surface**, and every role reads something another
 * role writes to it. So it is one stamp for the whole school, read by every
 * surface through `partials/workspace-live`, and there is deliberately not a
 * second list per role — two lists are two answers to "has anything changed",
 * and the role whose list was missing a table is the role that quietly stops
 * seeing a colleague's work.
 *
 * The stamp is a fingerprint of row counts and last-touched timestamps. It
 * carries **no personal information**, which is what makes it safe to poll on
 * a timer and is why the pulse routes are exempt in `AuditSensitiveAccess`:
 * a page pays for the (much heavier) rebuild only when the stamp actually
 * moves.
 *
 * It is scoped to one school. A neighbouring school's write never moves this
 * school's stamp on a table that carries `institution_id`, and never reaches
 * this school's figures at all — the reads themselves are scoped, and
 * `SchoolHeadOverview` / `SchoolHeadHealthOverview` return nothing without an
 * institution rather than falling through to every school's children.
 *
 * The audit trail is deliberately not watched: every authenticated page view
 * writes to it, so a stamp that included it would report a change on every
 * poll and a page would rebuild itself forever.
 */
final class SchoolPulse
{
    /**
     * Tables whose rows can move any figure anybody reads, and who writes each:
     *
     *   student_health_records  class adviser (roster, baseline, endline)
     *   student_health_conditions  class adviser (declared conditions)
     *   medical_certificates    adviser / nurse / clinic (medical documents)
     *   parental_consent_forms  class adviser / parent (deworming consent)
     *   health_consent_forms    class adviser / parent (health services consent)
     *   health_assessments      school nurse (screening)
     *   feeding_attendances     feeding coordinator (marks)
     *   attendance_imports      feeding coordinator (sheets)
     *   consultations           nurse / clinic staff / clinic teacher (visits)
     *   clinic_notes            school nurse (observations)
     *   medicines               clinic (stock levels)
     *   medicine_dispenses      nurse / clinic teacher (issues)
     *   medicine_receipts       nurse / clinic staff (deliveries)
     *
     * `medical_certificates`, `student_health_conditions` and
     * `medicine_receipts` were on one role's list and not the other's before
     * these were merged, which is exactly the failure described above: a
     * document the adviser filed moved the adviser's stamp and not the head's.
     *
     * A table missing from the schema is skipped by `ChangeStamp`, so this list
     * may name one a migration has not reached yet.
     *
     * @var list<string>
     */
    public const WATCHED_TABLES = [
        'student_health_records',
        'student_health_conditions',
        'medical_certificates',
        'parental_consent_forms',
        'health_consent_forms',
        'health_assessments',
        'feeding_attendances',
        'attendance_imports',
        'consultations',
        'clinic_notes',
        'medicines',
        'medicine_dispenses',
        'medicine_receipts',
    ];

    /**
     * One stamp for the school, in **one** round trip whatever the number of
     * tables watched.
     *
     * This is polled every twenty seconds by every open tab in every role, so
     * its cost is paid over and over; a query per table would make the
     * cheapest thing the app does the most expensive.
     */
    public static function stamp(?int $institutionId): string
    {
        return ChangeStamp::forTables(self::WATCHED_TABLES, $institutionId);
    }
}
