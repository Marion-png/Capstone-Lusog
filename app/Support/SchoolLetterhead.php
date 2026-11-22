<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Each school's own seal: `resources/school-logos/<slug of its name>.png`,
     * e.g. `sta-ana-national-high-school.png`. It is looked up per school and
     * never shared: one file for every school printed Sta. Ana's seal on Davao
     * City National High School's forms. A school that has not dropped its seal
     * in is drawn the placeholder outline, never a neighbour's.
     *
     * The folder is outside `public/` on purpose. A seal is served only by the
     * `school-seal` route, and only to somebody signed in at that school, so
     * no account can open another school's seal, even by typing its address.
     */
    public const SCHOOL_LOGO_DIR = 'school-logos';

    /** The route that serves the signed-in school's seal: `school-seal/<slug>.png`. */
    public const SCHOOL_SEAL_ROUTE = 'school-seal';

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
     * The heading for the school a session is scoped to.
     *
     * @return array{school: string, address: string, contact: string, region: string, division: string, republic: string, department: string}
     */
    public static function for(?int $institutionId, string $schoolName = ''): array
    {
        $institution = $institutionId ? self::institution($institutionId) : null;

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
     * The school seal is the signed-in school's, as the address the
     * `school-seal` route serves it at. A school named that is not the
     * session's gets no seal at all: the route would refuse it, and a page
     * must never point at another school's seal.
     *
     * @return array{deped: ?string, school: ?string, doh: ?string}
     */
    public static function seals(?int $institutionId = null): array
    {
        return [
            'deped' => self::sealPath(self::DEPED_LOGO),
            'school' => self::schoolSealUrl($institutionId),
            'doh' => self::sealPath(self::DOH_LOGO),
        ];
    }

    /**
     * The seal file on disk for the given school (whether or not it is there
     * yet). The school is the one named, else the session's; with neither
     * there is no school seal to draw.
     */
    public static function schoolLogo(?int $institutionId = null): string
    {
        return resource_path(self::SCHOOL_LOGO_DIR.'/'.self::schoolSlug($institutionId).'.png');
    }

    /**
     * The signed-in school's seal, as the address a page draws it from, or
     * null where that school has none or another school was named.
     */
    private static function schoolSealUrl(?int $institutionId): ?string
    {
        $sessionSchool = self::sessionInstitutionId();

        if ($sessionSchool === null || ($institutionId !== null && $institutionId !== $sessionSchool)) {
            return null;
        }

        $file = self::schoolLogo($sessionSchool);

        return is_file($file) ? self::SCHOOL_SEAL_ROUTE.'/'.basename($file) : null;
    }

    /**
     * The `school-seal` route: the seal of the school the request is signed in
     * at, and only when the address names that school's own seal. Anything
     * else — another school's seal, no school, no sign-in — is not found.
     *
     * The browser asks again on every use (`no-cache`) and is told "not
     * changed" for its own seal: a cached copy is never shown to the next
     * account signed in on the same browser without the server agreeing.
     */
    public static function schoolSealResponse(Request $request, string $seal): Response
    {
        $institutionId = self::sessionInstitutionId();

        abort_if($institutionId === null || ! $request->session()->has('active_role'), 404);

        $file = self::schoolLogo($institutionId);

        abort_unless($seal === basename($file) && is_file($file), 404);

        $response = response()->file($file, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }

    private static function schoolSlug(?int $institutionId): string
    {
        $slug = Str::slug(self::schoolNameFor($institutionId));

        return $slug !== '' ? $slug : '-no-school';
    }

    private static function sessionInstitutionId(): ?int
    {
        $request = request();
        $session = $request?->hasSession() ? $request->session() : null;

        return ((int) ($session?->get('active_institution_id') ?? 0)) ?: null;
    }

    /**
     * The school's name from its `institutions` row: the one named, else the
     * session's. The row is read once per request and shared with for(), so a
     * page that prints the heading and the seals pays one round trip.
     */
    private static function schoolNameFor(?int $institutionId): string
    {
        $id = $institutionId ?: self::sessionInstitutionId();

        return $id === null ? '' : trim((string) self::institution($id)?->name);
    }

    private static function institution(int $institutionId): ?Institution
    {
        return RequestMemo::remember('letterhead-institution:'.$institutionId, fn () => SchemaCache::hasTable('institutions')
            ? Institution::query()->find($institutionId)
            : null);
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
     * For the workbooks that set the heading as text alone; the masterlist
     * embeds the seals beside masthead() instead (MasterlistSheet).
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
    public static function sealFiles(?int $institutionId = null): array
    {
        $file = static fn (string $relative): ?string => is_file(public_path($relative)) ? public_path($relative) : null;
        $school = self::schoolLogo($institutionId);

        return ['deped' => $file(self::DEPED_LOGO), 'school' => is_file($school) ? $school : null];
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
