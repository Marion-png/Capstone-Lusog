<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\HealthServiceSetting;

/**
 * Which health services a school offers in a school year — the School Nurse's
 * "Health Services Offered" setting.
 *
 * SIGLA is for public schools in general, and which services a school gives
 * depends on where it is: schistosomiasis and filariasis medicine, and the TAS
 * on Lymphatic Filariasis, are given only where those diseases are endemic. So
 * that is a setting per school and per school year, never a rule compiled in.
 * What IS compiled in is what holds everywhere (ConsentStanding::appliesTo):
 * Immunization (MR, Td) is for Grade 7 only and Iron Supplementation for
 * female learners only.
 *
 * A service the school does not offer reads **Not offered** on every consent
 * form, exactly as one the adviser did not shade at the top of the
 * Sulat-Pahibalo: consent is not asked for it, so it is never read as refused.
 *
 * A school year with no setting of its own carries the latest earlier year's
 * (else the earliest on record), so a school does not have to switch the same
 * services off every June; a school that has never saved one offers them all.
 */
final class HealthServicesOffered
{
    /** Who may change it: the School Nurse plans the school's health services. */
    public const EDIT_ROLES = ['school_nurse'];

    public static function supported(): bool
    {
        return SchemaCache::hasTable('health_services_offered');
    }

    /**
     * The services offered, as keys of HealthConsentForm::PLANNED_SERVICES, in
     * the letter's order. Every one when the school has no setting.
     *
     * @return list<string>
     */
    public static function for(?int $institutionId, string $schoolYear): array
    {
        $off = self::settingFor($institutionId, $schoolYear)['not_offered'] ?? [];

        return array_values(array_diff(array_keys(HealthConsentForm::PLANNED_SERVICES), $off));
    }

    /**
     * The saved setting a school year reads, and the year it was saved for —
     * which is another year when it was carried over. Null with none on file.
     *
     * @return array{school_year: string, not_offered: list<string>}|null
     */
    public static function settingFor(?int $institutionId, string $schoolYear): ?array
    {
        if (! $institutionId || ! self::supported()) {
            return null;
        }

        $rows = RequestMemo::remember('health-services:'.$institutionId, fn (): array => HealthServiceSetting::query()
            ->where('institution_id', $institutionId)
            ->orderBy('school_year')
            ->get(['school_year', 'not_offered'])
            ->map(fn (HealthServiceSetting $row): array => [
                'school_year' => (string) $row->school_year,
                'not_offered' => array_values(array_filter((array) $row->not_offered, 'is_string')),
            ])
            ->all());

        if ($rows === []) {
            return null;
        }

        $earlier = array_values(array_filter($rows, fn (array $row): bool => strcmp($row['school_year'], $schoolYear) <= 0));

        return $earlier !== [] ? $earlier[count($earlier) - 1] : $rows[0];
    }

    /**
     * Whether a service is offered. A heading (Pag Purga, Immunization) is
     * offered when a row under it is. Null offers everything: nothing is known
     * about the school.
     *
     * @param  list<string>|null  $offered  what for() returned
     */
    public static function isOffered(string $service, ?array $offered): bool
    {
        if ($offered === null || in_array($service, $offered, true)) {
            return true;
        }

        $children = array_keys(HealthConsentForm::SERVICES[$service]['children'] ?? []);

        return $children !== [] && array_intersect($children, $offered) !== [];
    }

    /**
     * Save one school year's setting.
     *
     * @param  list<string>  $offered  keys of HealthConsentForm::PLANNED_SERVICES
     */
    public static function save(int $institutionId, string $schoolYear, array $offered, string $by): HealthServiceSetting
    {
        $notOffered = array_values(array_diff(array_keys(HealthConsentForm::PLANNED_SERVICES), $offered));

        $setting = HealthServiceSetting::query()->firstOrNew([
            'institution_id' => $institutionId,
            'school_year' => $schoolYear,
        ]);

        // Assigned only when it changed, so a save that changed nothing writes
        // no audit entry.
        if (! $setting->exists || $setting->not_offered !== $notOffered) {
            $setting->not_offered = $notOffered;
            $setting->updated_by_name = $by;
            $setting->save();
        }

        return $setting;
    }

    public static function forget(): void
    {
        RequestMemo::forgetPrefix('health-services:');
    }
}
