<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clinic's consent list, its counts and one learner's form, from one
 * reading.
 *
 * The question the nurse brings to this page is "who may I give this service
 * to, and who still has no form?", so the list is every learner on the year's
 * roll — not only the ones a form has come back for — and each is read
 * through ConsentStanding, the one place that decides a learner's standing.
 * The cards are counted from the same rows the list prints, so the two can
 * never disagree.
 *
 * Two kinds of filter, as on every other tab in this app: the service, the
 * grade and the school year are scope (they move the cards and the list
 * together), while the consent status narrows the list alone. The name search
 * runs in the browser over the rows already on the page: a name sent in a
 * query string is written into the audit trail with the URL.
 *
 * Nothing here sends a full LRN to the page: every row carries the masked
 * one, and the form page is keyed by the record id.
 */
final class NurseConsentList
{
    /** @var array<string, string> */
    public const STATUS_FILTERS = [
        ConsentStanding::SERVICE_CONSENTED => 'Consented',
        ConsentStanding::SERVICE_DECLINED => 'Declined',
        ConsentStanding::SERVICE_NO_FORM => 'No form yet',
    ];

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, cards: array<string, mixed>}
     */
    public static function build(?int $institutionId, string $schoolYear, bool $currentYear, string $service, ?int $grade, string $status): array
    {
        $rows = self::rows($institutionId, $schoolYear, $currentYear, $service);

        $scoped = $grade !== null
            ? $rows->filter(fn (array $row): bool => $row['grade_number'] === $grade)->values()
            : $rows;

        $listed = $status !== ''
            ? $scoped->filter(fn (array $row): bool => self::matchesStatus($row['status'], $status, $service))->values()
            : $scoped;

        return ['rows' => $listed, 'cards' => self::cards($scoped, $service)];
    }

    /**
     * One learner's form as the clinic reads it: the standing for the chosen
     * service, the photo it came from, and the values the adviser confirmed.
     * Nothing here calls the photo reader.
     *
     * @return array<string, mixed>
     */
    public static function learner(StudentHealthRecord $record, string $service): array
    {
        $institutionId = (int) $record->institution_id;
        $schoolYear = (string) $record->school_year;
        [$gradeLabel, $sectionName] = FeedingBeneficiarySummary::splitSection((string) $record->section);

        $letter = self::letters($institutionId, $schoolYear, [(string) $record->student_id])->first();
        $upload = self::uploads(collect([$record]), $schoolYear)->first();
        $answer = ConsentStanding::answer($letter, $upload);

        // The record that answers, or failing that whatever is on file: a
        // letter still out, or a scan nobody has recorded the answer of.
        $source = $answer['source'] ?? ($upload?->file_path !== null ? 'upload' : ($letter !== null ? 'letter' : null));

        return [
            'record' => $record,
            'name' => (string) $record->student_name,
            'lrn_masked' => self::maskLrn((string) $record->student_id),
            'grade' => $gradeLabel,
            'section' => $sectionName,
            'adviser' => self::advisers($institutionId)[self::classKey($gradeLabel, $sectionName)] ?? '',
            'school_year' => $schoolYear,
            'service' => $service,
            'status' => $service !== ''
                ? ConsentStanding::forService($service, LearnerPlacement::gradeNumber($gradeLabel), true, $answer)
                : ConsentStanding::overall(true, $answer),
            'answer' => $answer,
            'source' => $source,
            'letter' => $letter,
            'upload' => $upload,
            'photo' => self::photo($source, $letter, $upload),
            'values' => $answer !== null ? self::values($source, $letter, $upload, $answer) : [],
            'missing' => $answer !== null ? self::missing($source, $letter, $upload, $answer) : [],
            'filed' => self::filed($source, $letter, $upload),
        ];
    }

    /** All but the last five digits hidden — the only LRN this page sends. */
    public static function maskLrn(string $lrn): string
    {
        $lrn = trim($lrn);
        $visible = substr($lrn, -5);

        return str_repeat('•', max(0, strlen($lrn) - strlen($visible))).$visible;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function rows(?int $institutionId, string $schoolYear, bool $currentYear, string $service): Collection
    {
        // Scope is required: with no school there is no roll to read.
        if (! $institutionId) {
            return collect();
        }

        $records = StudentHealthRecord::rosterFor($institutionId, $schoolYear);

        // This year's list is the learners still in a section: one who has
        // left is on Archived Students, not on a list a service round is
        // planned from. An earlier year is a record, so it keeps everyone.
        if ($currentYear && LearnerPlacement::supported()) {
            $records = $records->filter(fn (StudentHealthRecord $record): bool => LearnerPlacement::isOpen($record));
        }

        $letters = self::letters($institutionId, $schoolYear, $records->pluck('student_id')->map(fn ($l) => (string) $l)->all());
        $uploads = self::uploads($records, $schoolYear);
        $advisers = self::advisers($institutionId);

        return $records->map(function (StudentHealthRecord $record) use ($letters, $uploads, $advisers, $service): array {
            [$gradeLabel, $sectionName] = FeedingBeneficiarySummary::splitSection((string) $record->section);
            $gradeNumber = LearnerPlacement::gradeNumber($gradeLabel);

            $letter = $letters->get((string) $record->student_id);
            $upload = $uploads->get($record->id);
            $answer = ConsentStanding::answer($letter, $upload);

            return [
                'id' => $record->id,
                'name' => (string) $record->student_name,
                'lrn_masked' => self::maskLrn((string) $record->student_id),
                'grade' => $gradeLabel,
                'grade_number' => $gradeNumber,
                'section' => $sectionName,
                'adviser' => $advisers[self::classKey($gradeLabel, $sectionName)] ?? '',
                'new_enrollee' => (string) ($record->joined_as ?? '') === LearnerPlacement::NEW_ENROLLEE,
                'status' => $service !== ''
                    ? ConsentStanding::forService($service, $gradeNumber, true, $answer)
                    : ConsentStanding::overall(true, $answer),
                'answer' => $answer,
                'dated_label' => self::datedLabel($letter, $upload, $answer),
                'view_url' => route('consent-forms.nurse-learner', array_filter([
                    'record' => $record->id,
                    'service' => $service !== '' ? $service : null,
                ])),
            ];
        })
            ->sortBy([
                fn (array $a, array $b): int => ($a['grade_number'] ?? 99) <=> ($b['grade_number'] ?? 99),
                fn (array $a, array $b): int => strnatcasecmp($a['section'], $b['section']),
                fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']),
            ])
            ->values();
    }

    /**
     * The four figures, over the learners the service applies to.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function cards(Collection $rows, string $service): array
    {
        $applicable = $rows->reject(fn (array $row): bool => in_array($row['status'], ConsentStanding::UNCOUNTED, true));
        $count = fn (string $status): int => $applicable->filter(fn (array $row): bool => $row['status'] === $status)->count();

        $consented = $applicable->filter(fn (array $row): bool => self::matchesStatus($row['status'], ConsentStanding::SERVICE_CONSENTED, $service))->count();
        $total = $applicable->count();

        return [
            'applicable' => $total,
            'consented' => $consented,
            'with_exceptions' => $count(ConsentStanding::SERVICE_WITH_EXCEPTIONS),
            'check_exceptions' => $count(ConsentStanding::SERVICE_CHECK_EXCEPTIONS),
            'declined' => $count(ConsentStanding::SERVICE_DECLINED),
            'no_form' => $count(ConsentStanding::SERVICE_NO_FORM),
            // A whole percent, or none at all: a rate over nobody is not 0%.
            'rate' => $total > 0 ? (int) round($consented * 100 / $total) : null,
        ];
    }

    /**
     * Whether a standing is the answer a status filter asked for. With every
     * service, a parent who agreed *except* for some did consent, so
     * "Consented" includes them; the card says how many.
     */
    private static function matchesStatus(string $standing, string $filter, string $service): bool
    {
        if ($filter === ConsentStanding::SERVICE_CONSENTED && $service === '') {
            return in_array($standing, [ConsentStanding::SERVICE_CONSENTED, ConsentStanding::SERVICE_WITH_EXCEPTIONS], true);
        }

        return $standing === $filter;
    }

    /**
     * The year's letters for these learners, keyed by LRN. A draft nobody
     * sent is not a form; a letter still out is on file but answers nothing.
     *
     * @param  list<string>  $lrns
     * @return Collection<string, HealthConsentForm>
     */
    private static function letters(int $institutionId, string $schoolYear, array $lrns): Collection
    {
        if ($lrns === []) {
            return collect();
        }

        return HealthConsentForm::where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->whereIn('student_lrn', $lrns)
            ->whereIn('status', [
                HealthConsentForm::STATUS_SENT,
                HealthConsentForm::STATUS_SIGNED,
                HealthConsentForm::STATUS_REVIEWED,
            ])
            ->get()
            ->keyBy(fn (HealthConsentForm $form): string => (string) $form->student_lrn);
    }

    /**
     * The year's latest uploaded scan per learner, keyed by record id.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return Collection<int, ParentalConsentForm>
     */
    private static function uploads(Collection $records, string $schoolYear): Collection
    {
        if ($records->isEmpty() || ! SchemaCache::hasTable('parental_consent_forms')) {
            return collect();
        }

        // Oldest first, so keyBy leaves the most recent upload per learner. The
        // health-services letter only — a feeding-programme consent is not one.
        return ParentalConsentForm::healthServices()
            ->whereIn('student_health_record_id', $records->pluck('id'))
            ->where('school_year', $schoolYear)
            ->get()
            ->sortBy('created_at')
            ->keyBy('student_health_record_id');
    }

    /**
     * Each class's adviser, by grade and section, in one read for the school.
     *
     * @return array<string, string>
     */
    private static function advisers(int $institutionId): array
    {
        return RequestMemo::remember('nurse_consent_advisers:'.$institutionId, function () use ($institutionId): array {
            $names = [];

            foreach (DB::table('accounts')
                ->where('institution_id', $institutionId)
                ->where('role', 'class_adviser')
                ->get(['name', 'assigned_grade_level', 'assigned_section']) as $account) {
                $key = self::classKey((string) $account->assigned_grade_level, (string) $account->assigned_section);
                $name = trim((string) $account->name);

                if ($name !== '') {
                    $names[$key][] = $name;
                }
            }

            return array_map(fn (array $list): string => implode(', ', array_unique($list)), $names);
        });
    }

    private static function classKey(string $grade, string $section): string
    {
        return strtolower(trim($grade)).'|'.strtolower(trim($section));
    }

    /** @param  array<string, mixed>|null  $answer */
    private static function datedLabel(?HealthConsentForm $letter, ?ParentalConsentForm $upload, ?array $answer): string
    {
        return match ($answer['source'] ?? null) {
            'letter' => 'Signed '.optional($letter?->signed_at)->format('M j, Y'),
            'upload' => 'Uploaded '.optional($upload?->created_at)->format('M j, Y'),
            default => $letter?->status === HealthConsentForm::STATUS_SENT
                ? 'Letter sent '.optional($letter->sent_at)->format('M j, Y')
                : '',
        };
    }

    /**
     * The picture of the signed paper, if there is one to show. An online
     * signature has none; a PDF scan is opened rather than previewed.
     *
     * @return array{url: string|null, is_image: bool, online: bool}
     */
    private static function photo(?string $source, ?HealthConsentForm $letter, ?ParentalConsentForm $upload): array
    {
        if ($source === 'letter' && $letter !== null) {
            $path = (string) $letter->paper_form_path;

            return [
                'url' => $path !== '' ? route('consent-forms.paper.image', $letter) : null,
                'is_image' => $path !== '',
                'online' => $path === '' && in_array($letter->status, [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED], true),
            ];
        }

        if ($source === 'upload' && $upload !== null && $upload->file_path !== null) {
            $extension = strtolower(pathinfo((string) ($upload->file_original_name ?: $upload->file_path), PATHINFO_EXTENSION));

            return [
                'url' => route('parental-consent.download', $upload->id),
                'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true),
                'online' => false,
            ];
        }

        return ['url' => null, 'is_image' => false, 'online' => false];
    }

    /**
     * The values the adviser confirmed, as the clinic reads them.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, string>
     */
    private static function values(?string $source, ?HealthConsentForm $letter, ?ParentalConsentForm $upload, array $answer): array
    {
        $choice = $answer['choice'];
        $isLetter = $source === 'letter' && $letter !== null;

        $reason = $isLetter ? trim((string) $letter->refusal_reason) : trim((string) $upload?->refused_reason);

        $allergies = $isLetter
            ? array_filter([
                'Food' => trim((string) $letter->allergy_food),
                'Medicine' => trim((string) $letter->allergy_medicine),
                'Previous immunization' => trim((string) $letter->prev_immunization),
            ])
            : array_filter([
                'Food' => $upload?->allergy_food ? (trim((string) $upload->allergy_food_detail) ?: 'type not written') : '',
                'Medicine' => $upload?->allergy_medicine ? (trim((string) $upload->allergy_medicine_detail) ?: 'type not written') : '',
                'Previous immunization' => $upload?->prev_immunization ? (trim((string) $upload->prev_immunization_detail) ?: 'type not written') : '',
            ]);

        $illness = $isLetter
            ? trim((string) $letter->other_illness)
            : ($upload?->has_other_illness ? (trim((string) $upload->other_illness_detail) ?: 'Indicated, not described') : '');

        return [
            'Parent decision' => match ($choice) {
                HealthConsentForm::CONSENT_ALL => 'Consents to all services',
                HealthConsentForm::CONSENT_SPECIFIC => 'Consents except listed services',
                default => 'Declines',
            },
            'Excluded services' => match (true) {
                $choice !== HealthConsentForm::CONSENT_SPECIFIC => 'None',
                $answer['excluded'] !== null => implode(', ', array_map(
                    fn (string $key): string => HealthConsentForm::PLANNED_SERVICES[$key] ?? HealthConsentForm::serviceName($key),
                    $answer['excluded'],
                )) ?: 'None',
                $answer['exceptions'] !== '' => 'As written by the parent: “'.$answer['exceptions'].'”',
                default => 'Not written on the form',
            },
            'Reason given' => $choice === HealthConsentForm::CONSENT_DENY ? ($reason !== '' ? $reason : 'Not written on the form') : 'Not required',
            'Allergies' => $allergies !== []
                ? implode(' · ', array_map(fn (string $kind, string $what): string => $kind.': '.$what, array_keys($allergies), $allergies))
                : 'None indicated',
            'Other illnesses' => $illness !== '' ? $illness : 'None indicated',
            'Signatures' => self::signatures($isLetter ? $letter : null),
        ];
    }

    /**
     * What is known about the two signatures. A parent who signs online leaves
     * a drawn signature on the record; for a signed paper form the photo is
     * the evidence and nobody has recorded what it shows, so the page says
     * that rather than claiming either way.
     */
    private static function signatures(?HealthConsentForm $letter): string
    {
        if ($letter !== null && trim((string) $letter->signature) !== '') {
            return 'Parent/guardian: present (signed online) · School head: printed on the letter';
        }

        return 'Not recorded — check the photo of the signed form';
    }

    /**
     * The required fields the record is missing. Unrecorded is not missing:
     * a signature nobody was asked to confirm is not listed here.
     *
     * @param  array<string, mixed>  $answer
     * @return list<string>
     */
    private static function missing(?string $source, ?HealthConsentForm $letter, ?ParentalConsentForm $upload, array $answer): array
    {
        $missing = [];
        $isLetter = $source === 'letter' && $letter !== null;

        if ($answer['choice'] === HealthConsentForm::CONSENT_SPECIFIC && $answer['exceptions'] === '' && $answer['excluded'] === null) {
            $missing[] = 'Excluded services';
        }

        if ($answer['choice'] === HealthConsentForm::CONSENT_DENY) {
            $reason = $isLetter ? trim((string) $letter->refusal_reason) : trim((string) $upload?->refused_reason);

            if ($reason === '') {
                $missing[] = 'Reason given';
            }
        }

        if ($isLetter && trim((string) $letter->parent_guardian_name) === '') {
            $missing[] = 'Parent or guardian\'s name';
        }

        if ($isLetter && trim((string) $letter->paper_form_path) === '' && trim((string) $letter->signature) === '') {
            $missing[] = 'Parent or guardian\'s signature';
        }

        return $missing;
    }

    /**
     * Who filed the form and when.
     *
     * @return array{by: string, on: string, how: string}|null
     */
    private static function filed(?string $source, ?HealthConsentForm $letter, ?ParentalConsentForm $upload): ?array
    {
        if ($source === 'upload' && $upload !== null) {
            return [
                'by' => trim((string) $upload->uploaded_by_name) ?: 'the class adviser',
                'on' => (string) optional($upload->created_at)->format('M j, Y'),
                'how' => 'Uploaded',
            ];
        }

        if ($source === 'letter' && $letter !== null) {
            if (trim((string) $letter->paper_form_path) !== '') {
                $entry = collect((array) ($letter->audit ?? []))
                    ->last(fn ($entry): bool => is_array($entry) && ($entry['action'] ?? '') === 'Recorded from the signed paper form');

                return [
                    'by' => trim((string) ($entry['actor_name'] ?? '')) ?: (trim((string) $letter->created_by_name) ?: 'the class adviser'),
                    'on' => (string) optional($letter->signed_at)->format('M j, Y'),
                    'how' => 'Uploaded',
                ];
            }

            return [
                'by' => trim((string) $letter->created_by_name) ?: 'the class adviser',
                'on' => (string) optional($letter->signed_at ?? $letter->sent_at)->format('M j, Y'),
                'how' => 'Sent',
            ];
        }

        return null;
    }
}
