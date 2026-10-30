<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use Illuminate\Support\Str;

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
     * The same two lines as the school's own office letterhead sets them —
     * in Filipino, above the division — which the masterlist is headed with.
     */
    public const REPUBLIC_FIL = 'Republika ng Pilipinas';

    public const DEPARTMENT_FIL = 'Kagawaran ng Edukasyon';

    /** The office the masterlist's letterhead is issued from. */
    public const OFFICE = 'OFFICE OF THE PRINCIPAL';

    /**
     * What a school's own letterhead carries that the app holds nowhere else,
     * keyed by the school's name in lower case.
     *
     * Matched by name and never assumed for anybody else, for the reason the
     * address fallback always was: one school's street, landline or e-mail on
     * another school's submitted form is worse than a gap in it. A school not
     * listed prints its stored address and no contact line.
     *
     * @var array<string, array{address: string, contact: string}>
     */
    private const OFFICIAL = [
        'sta. ana national high school' => [
            'address' => 'Sales Street, Davao City',
            'contact' => 'Landline No. (082) 222-44-21 • Email: staana.nhs@deped.gov.ph',
        ],
    ];

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
     * The Department of Health seal.
     *
     * Most forms here are DepEd's alone and carry the department seal beside
     * the school's. The Sulat-Pahibalo is the exception: the health services
     * it consents to are run with the DOH and the LGU, so the printed form
     * heads DepEd on the left and DOH on the right. Looked up the same way as
     * the others, so a school that drops the file in gets it with no code
     * change and one that has not is drawn a placeholder.
     */
    public const DOH_LOGO = 'images/doh-logo.png';

    /**
     * Where a school's own office letterhead is kept: one picture per school,
     * named after it (`Str::slug($name).png`).
     *
     * The letterhead a school heads its own lists with — the two seals on the
     * left, its name, its street, "Office of the Principal" and how to reach it
     * set right beside them — is a designed block the school already has. A
     * spreadsheet cannot set left-aligned text beside a picture without bending
     * the table's own columns to fit it, so the block travels into a workbook
     * as the one picture it is. Keyed by the school's own name, so one school's
     * street can never head another school's list; a school with no picture on
     * file gets the same lines as text (officeLines()).
     */
    public const OFFICE_LETTERHEADS = 'images/letterheads';

    /** The office letterhead's Republic / Department lines, as the school's template sets them. */
    public const REPUBLIC_FIL = 'Republika ng Pilipinas';

    public const DEPARTMENT_FIL = 'Kagawaran ng Edukasyon';

    public const OFFICE = 'OFFICE OF THE PRINCIPAL';

    /**
     * The heading for the school a session is scoped to.
     *
     * @return array{school: string, address: string, contact: string, region: string, division: string, republic: string, department: string}
     */
    public static function for(?int $institutionId, string $schoolName = ''): array
    {
        $institution = null;

        if ($institutionId && SchemaCache::hasTable('institutions')) {
            $institution = Institution::query()->find($institutionId);
        }

        $name = trim((string) ($institution?->name ?: $schoolName));
        $address = trim((string) ($institution?->address ?? ''));
        $official = self::OFFICIAL[strtolower($name)] ?? null;

        // A school with no address on file falls back to its own letterhead's,
        // matched by name rather than assumed for everybody, because putting
        // one school's street on another school's submitted form is exactly
        // the error this class exists to avoid.
        if ($address === '' && $official !== null) {
            $address = $official['address'];
        }

        return [
            'school' => $name !== '' ? $name : 'School name not set',
            // Empty is a real answer: the form prints a line to write on.
            'address' => $address,
            // The landline and e-mail line, or nothing at all.
            'contact' => $official['contact'] ?? '',
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
     * @return array{deped: ?string, school: ?string, doh: ?string}
     */
    public static function seals(): array
    {
        return [
            'deped' => self::sealPath(self::DEPED_LOGO),
            'school' => self::sealPath(self::SCHOOL_LOGO),
            'doh' => self::sealPath(self::DOH_LOGO),
        ];
    }

    /**
     * The seal's path, stamped with the file's own modification time.
     *
     * A seal is dropped in by hand and often replaced a second time — the first
     * copy still has its white box, or it was cropped too tight. Without the
     * stamp the browser keeps serving the copy it cached under the same
     * filename, and the school concludes the form is broken when what they are
     * looking at is yesterday's file. The name is what the app looks up; the
     * stamp is only what the browser caches against.
     */
    private static function sealPath(string $relative): ?string
    {
        $absolute = public_path($relative);

        if (! is_file($absolute)) {
            return null;
        }

        $stamp = @filemtime($absolute);

        return $stamp ? $relative.'?v='.$stamp : $relative;
    }

    /**
     * The heading as the lines a workbook writes, top to bottom.
     *
<<<<<<< Updated upstream
     * For the workbooks that set the heading as text alone; the masterlist
     * embeds the seals beside masthead() instead (MasterlistSheet).
=======
     * The text block is what makes an exported sheet the same document as the
     * printed one where the seals are not carried. The masterlist heads itself
     * with the school's own office letterhead instead (officeBanner()).
>>>>>>> Stashed changes
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
     * The masterlist's letterhead, top to bottom, as the school's own office
     * letterhead sets it beside the two seals: Republika / Kagawaran, the
     * division, the school, its address, the office and its landline and
     * e-mail. Each line names its role, so the printed form and the workbook
     * set every line the same way. An address nobody holds stays as a line to
     * write on; a contact line nobody holds is left out.
     *
     * @param  array<string, string>  $letterhead  for()
     * @return list<array{role: string, text: string}>
     */
    public static function masthead(array $letterhead): array
    {
        $lines = [
            ['role' => 'republic', 'text' => self::REPUBLIC_FIL],
            ['role' => 'department', 'text' => self::DEPARTMENT_FIL],
            ['role' => 'division', 'text' => 'Division of '.ucwords(strtolower(trim($letterhead['division']))).' Schools'],
            ['role' => 'school', 'text' => mb_strtoupper($letterhead['school'])],
            ['role' => 'address', 'text' => $letterhead['address']],
            ['role' => 'office', 'text' => self::OFFICE],
            ['role' => 'contact', 'text' => $letterhead['contact'] ?? ''],
        ];

        return array_values(array_filter($lines, fn (array $line): bool => $line['role'] !== 'contact' || $line['text'] !== ''));
    }

    /**
     * The seals as files on disk, for a workbook that embeds them — or null
     * where a school has not dropped one in.
     *
     * @return array{deped: ?string, school: ?string}
     */
    public static function sealFiles(): array
    {
        $file = static fn (string $relative): ?string => is_file(public_path($relative)) ? public_path($relative) : null;

        return ['deped' => $file(self::DEPED_LOGO), 'school' => $file(self::SCHOOL_LOGO)];
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

    /**
     * The school's office letterhead picture, as an absolute path — or null
     * when this school has none on file.
     */
    public static function officeBanner(string $schoolName): ?string
    {
        $slug = Str::slug($schoolName);

        if ($slug === '') {
            return null;
        }

        $path = public_path(self::OFFICE_LETTERHEADS.'/'.$slug.'.png');

        return is_file($path) ? $path : null;
    }

    /**
     * The office letterhead as text, top to bottom: the lines the school's own
     * template carries, in its own language, for a school whose letterhead
     * picture is not on file. The address is the one on record — empty is a
     * line to write on, never a neighbouring school's street.
     *
     * @param  array<string, string>  $letterhead  for()
     * @return list<string>
     */
    public static function officeLines(array $letterhead): array
    {
        return [
            self::REPUBLIC_FIL,
            self::DEPARTMENT_FIL,
            'Division of '.ucwords(strtolower(trim($letterhead['division']))).' Schools',
            strtoupper($letterhead['school']),
            $letterhead['address'],
            self::OFFICE,
        ];
    }
}
