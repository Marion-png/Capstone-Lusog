<?php

namespace App\Support;

use App\Models\StudentHealthRecord;

/**
 * The CLASS MASTERLIST filled with one class's learners, for the clinic.
 *
 * The School Nurse and the Clinic Teacher see the whole school, so they can
 * download any class's list; the form itself is ClassMasterlistTemplate's, the
 * same one an adviser fills by hand. It reads the school's roster once
 * (StudentHealthRecord::rosterFor) and lists learners still in the class this
 * year — an archived learner, or one who has left the section, is not on it.
 *
 * A missing school reads nothing, never every school.
 */
final class ClassMasterlistRoster
{
    /**
     * Every class on the school's roll this year, as the plain section column
     * stores it ("Grade 7 / Matiyaga"), in natural order.
     *
     * @return list<string>
     */
    public static function classes(?int $institutionId): array
    {
        $classes = [];

        foreach (self::openRecords($institutionId) as $record) {
            [$grade, $section] = self::split((string) $record->section);

            if ($grade !== '' && $section !== '') {
                $classes[$grade.' / '.$section] = true;
            }
        }

        $classes = array_keys($classes);
        usort($classes, 'strnatcasecmp');

        return $classes;
    }

    /**
     * The filled form for one class, or null when the school holds no such
     * class this year.
     *
     * @return array{rows: list<array{cells: list<string>, register: string}>, merges: list<array{0: int, 1: int, 2: int, 3: int}>, widths: list<int>, file_name: string}|null
     */
    public static function template(?int $institutionId, string $schoolName, string $class): ?array
    {
        if (! in_array($class, self::classes($institutionId), true)) {
            return null;
        }

        [$grade, $section] = self::split($class);

        $bands = [
            ClassMasterlistTemplate::BANDS[0] => [],
            ClassMasterlistTemplate::BANDS[1] => [],
            ClassMasterlistTemplate::UNRECORDED_BAND => [],
        ];

        foreach (self::openRecords($institutionId) as $record) {
            if (self::split((string) $record->section) !== [$grade, $section]) {
                continue;
            }

            $band = match (FeedingBeneficiarySummary::sexOf($record)) {
                'Male' => ClassMasterlistTemplate::BANDS[0],
                'Female' => ClassMasterlistTemplate::BANDS[1],
                default => ClassMasterlistTemplate::UNRECORDED_BAND,
            };

            $bands[$band][] = self::learner($record);
        }

        foreach ($bands as $band => $learners) {
            usort($learners, static fn (array $a, array $b): int => strnatcasecmp($a['last'], $b['last'])
                ?: strnatcasecmp($a['first'], $b['first'])
                ?: strnatcasecmp($a['middle'], $b['middle']));
            $bands[$band] = $learners;
        }

        return ClassMasterlistTemplate::build(
            SchoolLetterhead::for($institutionId, $schoolName),
            ClassMasterlistTemplate::classLabel($grade, $section),
            StudentHealthRecord::currentSchoolYear(),
            SchoolSignatories::classAdviser($institutionId, $grade, $section),
            $bands,
        );
    }

    /** @return iterable<StudentHealthRecord> */
    private static function openRecords(?int $institutionId): iterable
    {
        if (! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        $placement = LearnerPlacement::supported();

        return StudentHealthRecord::rosterFor($institutionId)
            ->filter(static fn (StudentHealthRecord $record): bool => ! $placement || LearnerPlacement::isOpen($record));
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $class): array
    {
        $parts = explode(' / ', $class, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    /**
     * The three name columns and the LRN. The adviser's entry keeps the name
     * in parts; an older record has only "Last, First M." to split.
     *
     * @return array{last: string, first: string, middle: string, lrn: string}
     */
    private static function learner(StudentHealthRecord $record): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $last = trim((string) ($details['last_name'] ?? ''));
        $first = trim((string) ($details['first_name'] ?? ''));
        $middle = trim((string) ($details['middle_name'] ?? ''));

        if ($last === '' && $first === '') {
            $parts = explode(',', (string) $record->student_name, 2);
            $last = trim($parts[0]);
            $first = trim($parts[1] ?? '');
        }

        return [
            'last' => $last,
            'first' => $first,
            'middle' => $middle,
            'lrn' => (string) $record->student_id,
        ];
    }
}
