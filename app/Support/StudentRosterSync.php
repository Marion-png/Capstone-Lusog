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

        $existing = collect($request->session()->get('school_health_card_records', []));
        $sessionLrns = $existing
            ->pluck('lrn')
            ->filter()
            ->map(fn ($v) => (string) $v)
            ->flip();

        $dbRecords = StudentHealthRecord::currentYearForInstitution($institutionId);

        $toAdd = [];
        foreach ($dbRecords as $record) {
            $lrn = (string) $record->student_id;

            if ($sessionLrns->has($lrn)) {
                continue;
            }

            $toAdd[] = self::buildSessionRow($record, $lrn);
            $sessionLrns->put($lrn, true);
        }

        if (! empty($toAdd)) {
            $request->session()->put(
                'school_health_card_records',
                array_merge($existing->all(), $toAdd)
            );
        }
    }

    private static function buildSessionRow(StudentHealthRecord $record, string $lrn): array
    {
        // Records saved with the full adviser entry restore every field
        // (birth date, guardian, address, contact, gender, ...).
        $row = is_array($record->student_details) ? $record->student_details : [];

        if ($row === []) {
            $row = self::rowFromLegacyColumns($record);
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
     * reconstructed from "LastName, FirstName Middle" and "Grade X / Section".
     */
    private static function rowFromLegacyColumns(StudentHealthRecord $record): array
    {
        $name = (string) $record->student_name;
        $lastName = $name;
        $firstName = '';
        $middleName = '';

        $commaPos = strpos($name, ', ');
        if ($commaPos !== false) {
            $lastName = substr($name, 0, $commaPos);
            $rest = substr($name, $commaPos + 2);
            $spacePos = strpos($rest, ' ');
            if ($spacePos !== false) {
                $firstName = substr($rest, 0, $spacePos);
                $middleName = substr($rest, $spacePos + 1);
            } else {
                $firstName = $rest;
            }
        }

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
}
