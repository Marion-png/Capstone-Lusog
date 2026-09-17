<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Illuminate\Http\Request;

/**
 * Rebuilds the session roster (`school_health_card_records`) from the
 * database, so adviser- and nurse-facing pages survive session expiry,
 * re-login, and server restarts. The database is the source of truth;
 * the session is only a working copy. Records from other institutions
 * are never added.
 */
class StudentRosterSync
{
    public static function syncToSession(Request $request): void
    {
        $institutionId = $request->session()->get('active_institution_id');

        if (! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            return;
        }

        $existing = $request->session()->get('school_health_card_records', []);
        $existing = is_array($existing) ? $existing : [];

        // Raw session index per LRN. Rows are updated IN PLACE rather than
        // rebuilt, because the nurse's "Fill Medical Record" link is keyed by
        // that index (NurseController::examine) and must keep opening the
        // learner it was rendered for.
        $indexByLrn = [];
        foreach ($existing as $index => $row) {
            $lrn = is_array($row) ? (string) ($row['lrn'] ?? '') : '';
            if ($lrn !== '' && ! isset($indexByLrn[$lrn])) {
                $indexByLrn[$lrn] = $index;
            }
        }

        $records = $existing;
        $seen = [];
        foreach (StudentHealthRecord::currentYearForInstitution($institutionId) as $record) {
            $lrn = (string) $record->student_id;
            if ($lrn === '' || isset($seen[$lrn])) {
                continue;
            }
            $seen[$lrn] = true;

            $row = self::buildSessionRow($record, $lrn);

            // The database is the source of truth, so a row already in the
            // session is refreshed from it: an adviser correcting a learner's
            // name used to reach the nurse only once their session expired,
            // because the sync added missing LRNs and never touched the rest.
            if (isset($indexByLrn[$lrn])) {
                $records[$indexByLrn[$lrn]] = $row;
            } else {
                $records[] = $row;
            }
        }

        if ($records !== $existing) {
            $request->session()->put('school_health_card_records', $records);
        }
    }

    private static function buildSessionRow(StudentHealthRecord $record, string $lrn): array
    {
        // Records saved with the full adviser entry restore every field
        // (birth date, guardian, address, contact, gender, ...).
        $row = is_array($record->student_details) ? $record->student_details : [];

        // A blob with no name in it is not a full adviser entry, whatever else
        // it holds: the nurse's vital signs are written into student_details
        // too (StudentVitalSigns::write), so an older record that only had the
        // nutrition columns ends up with a non-empty blob and no name, and
        // used to render as "-" on every roster. Any field the blob lacks is
        // taken from the legacy columns instead.
        if (self::isBlank($row['last_name'] ?? null) && self::isBlank($row['first_name'] ?? null)) {
            $row = array_merge(
                self::rowFromLegacyColumns($record),
                array_filter($row, fn ($value): bool => ! self::isBlank($value))
            );
        }

        $row['lrn'] = $lrn;
        // Nurse examination and feeding attendance live in their own columns.
        $row['examination'] = $record->examination ?? [];
        $row['attendance_by_month'] = $record->attendance_by_month ?? [];

        return self::withoutSignature($row);
    }

    /**
     * Drop the examiner signature image from a roster row, leaving a flag in
     * its place.
     *
     * The signature is a base64 data URL — tens of kilobytes drawn, up to
     * ~2.8MB uploaded. The session holds every learner in the class and is
     * decrypted, read, and rewritten on every request, so keeping the images
     * there would grow it without bound. `student_details` remains the store.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withoutSignature(array $row): array
    {
        if (! isset($row['systems_review']) || ! is_array($row['systems_review'])) {
            return $row;
        }

        $signature = $row['systems_review']['examiner_signature'] ?? null;

        $row['systems_review']['examiner_signature'] = null;
        $row['systems_review']['examiner_signature_present'] = is_string($signature) && $signature !== '';

        return $row;
    }

    /**
     * Older rows only stored the nutrition summary, so the roster entry is
     * reconstructed from "LastName, FirstName M." and "Grade X / Section".
     */
    private static function rowFromLegacyColumns(StudentHealthRecord $record): array
    {
        [$lastName, $firstName, $middleName] = self::splitStudentName((string) $record->student_name);

        $sectionParts = explode(' / ', (string) $record->section, 2);

        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'grade_level' => $sectionParts[0] ?? (string) $record->section,
            'section' => $sectionParts[1] ?? '',
            'height_cm' => $record->baseline_height_cm,
            'weight_kg' => $record->baseline_weight_kg,
            'age' => $record->baseline_age,
            'bmi_value' => $record->bmi_value,
            'nutritional_status_bmi_for_age' => $record->nutritional_status,
            'nutritional_status_height_for_age' => null,
        ];
    }

    /**
     * "Dela Cruz, Maria Clara S." → ['Dela Cruz', 'Maria Clara', 'S.'].
     *
     * The stored name carries the middle name as an INITIAL
     * (AdviserController::buildStudentName), so only a trailing initial is
     * read as the middle name; everything else after the comma is the first
     * name. Splitting on the first space, as this used to, turned a two-word
     * first name into a middle name and printed "Maria Clara" as "Maria C.".
     * A name with no comma is kept whole as the last name rather than guessed
     * at.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function splitStudentName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        $commaPos = strpos($name, ',');
        if ($commaPos === false) {
            return [$name, '', ''];
        }

        $lastName = trim(substr($name, 0, $commaPos));
        $rest = trim(substr($name, $commaPos + 1));
        $middleName = '';

        if (preg_match('/^(.*\S)\s+(\p{L}\.?)$/u', $rest, $m) === 1) {
            $rest = $m[1];
            $middleName = $m[2];
        }

        return [$lastName, $rest, $middleName];
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
