<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

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
        $parts = [];

        foreach (self::WATCHED_TABLES as $table) {
            if (! SchemaCache::hasTable($table)) {
                $parts[] = '-';

                continue;
            }

            $query = DB::table($table);

            // The child tables inherit their school scope from the parent
            // record, so only the owning tables filter. A neighbouring school's
            // write can therefore cost one needless refetch — never a missed
            // change, and nothing of theirs is ever read.
            if ($institutionId && SchemaCache::hasColumn($table, 'institution_id')) {
                $query->where('institution_id', $institutionId);
            }

            // An append-only table has no updated_at to read, so the newest row
            // stands in for "last touched".
            $touched = SchemaCache::hasColumn($table, 'updated_at') ? 'updated_at' : 'created_at';

            $row = $query->selectRaw('COUNT(*) as row_count, MAX('.$touched.') as last_touched')->first();
            $parts[] = ((int) ($row->row_count ?? 0)).'@'.((string) ($row->last_touched ?? ''));
        }

        return md5(implode('|', $parts));
    }
}
