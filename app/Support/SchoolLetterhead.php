<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\Institution;

/**
 * The heading every DepEd form in this app is printed under.
 *
 * A government form opens with the same block whatever the form is: the two
 * seals, the Republic / Department lines, the region and the division, then the
 * school and its address. It is read here in one place so the coordinator's
 * printed sheet, the School Head's export and the coordinator's own workbooks
 * cannot head the same school three different ways.
 *
 * Nothing here is personal information — a school's name and street address are
 * public facts about an institution, not facts about a child — so every field
 * is stored plain and may be read in SQL.
 *
 * The address comes from `institutions.address`, which the System Admin sets
 * when the school is provisioned. A school that has none prints an empty line
 * to write on rather than a neighbouring school's street: an invented address
 * on a submitted government form is worse than a gap in one.
 */
final class SchoolLetterhead
{
    /** The lines above the school name, as the printed form sets them. */
    public const REPUBLIC = 'Republic of the Philippines';

    public const DEPARTMENT = 'Department of Education';

    /**
     * The seals the form carries, left and right of the heading.
     *
     * They are looked up as files under `public/images` rather than hardcoded
     * into the markup, so a school that drops its own seal in gets it on every
     * form without a code change — and a school that has not is drawn a placed
     * outline rather than a broken image.
     */
    public const DEPED_LOGO = 'images/deped-logo.png';

    public const SCHOOL_LOGO = 'images/school-logo.png';

    /**
     * The heading for the school a session is scoped to.
     *
     * @return array{school: string, address: string, region: string, division: string, republic: string, department: string}
     */
    public static function for(?int $institutionId, string $schoolName = ''): array
    {
        $institution = null;

        if ($institutionId && SchemaCache::hasTable('institutions')) {
            $institution = Institution::query()->find($institutionId);
        }

        $name = trim((string) ($institution?->name ?: $schoolName));
        $address = trim((string) ($institution?->address ?? ''));

        // The one school whose address the app already holds — it is printed on
        // the Sulat-Pahibalo. It is matched by name rather than assumed for
        // everybody, because putting one school's street on another school's
        // submitted form is exactly the error this class exists to avoid.
        if ($address === '' && strcasecmp($name, HealthConsentForm::DEFAULT_SCHOOL_NAME) === 0) {
            $address = HealthConsentForm::DEFAULT_SCHOOL_ADDRESS;
        }

        return [
            'school' => $name !== '' ? $name : 'School name not set',
            // Empty is a real answer: the form prints a line to write on.
            'address' => $address,
            // The app serves one division, and neither is held per school yet.
            // They are named here rather than typed into four Blade files so
            // there is one place to widen when a second division arrives.
            'region' => HealthConsentForm::DEFAULT_REGION,
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'republic' => self::REPUBLIC,
            'department' => self::DEPARTMENT,
        ];
    }

    /**
     * The seal files that actually exist, so a page can draw a placeholder
     * instead of a broken image and a school without seals still prints a
     * correctly-shaped heading.
     *
     * @return array{deped: ?string, school: ?string}
     */
    public static function seals(): array
    {
        return [
            'deped' => self::sealPath(self::DEPED_LOGO),
            'school' => self::sealPath(self::SCHOOL_LOGO),
        ];
    }

    private static function sealPath(string $relative): ?string
    {
        return is_file(public_path($relative)) ? $relative : null;
    }

    /**
     * The heading as the lines a workbook writes, top to bottom.
     *
     * A spreadsheet carries no images, so the seals cannot travel into an
     * export — the text block is what makes the exported sheet the same
     * document as the printed one.
     *
     * @param  array<string, string>  $letterhead
     * @return list<string>
     */
    public static function lines(array $letterhead): array
    {
        return [
            $letterhead['republic'],
            $letterhead['department'],
            $letterhead['region'],
            self::divisionLine($letterhead['division']),
        ];
    }

    /**
     * "Schools Division of Davao City" — the division is stored upper-case
     * because the Sulat-Pahibalo prints it that way inside a filled field, but
     * a letterhead line is set in title case.
     */
    public static function divisionLine(string $division): string
    {
        return 'Schools Division of '.ucwords(strtolower(trim($division)));
    }
}
