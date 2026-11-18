<?php

namespace App\Models;

use App\Support\FeedingAtRiskRule;
use App\Support\FeedingProgramCycle;
use App\Support\HealthServicesOffered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    /**
     * A school's feeding settings are read once per request, because every
     * screen in the feeding module asks for them and several ask twice. Saving
     * this row is what makes those copies wrong, so it is also what discards
     * them — the System Admin can change a threshold and see the new figure on
     * the page the change redirects to.
     */
    protected static function booted(): void
    {
        static::saved(static function (): void {
            FeedingAtRiskRule::forgetInstitutionSettings();
            FeedingProgramCycle::forgetInstitutionSettings();
        });

        static::deleted(static function (): void {
            FeedingAtRiskRule::forgetInstitutionSettings();
            FeedingProgramCycle::forgetInstitutionSettings();
        });
    }

    protected $fillable = [
        'name',
        'address',
        'status',
        // The school's own feeding policy. Every one of these is NULL by
        // default, meaning "use the app default", so a school that has set
        // nothing moves with the programme rather than being pinned to whatever
        // the figure was the day the column shipped.
        'feeding_at_risk_threshold',
        'feeding_min_observation_days',
        'feeding_at_risk_mode',
        'feeding_absence_notice_days',
        'feeding_absence_flag_days',
        'feeding_absence_removal_days',
        'feeding_cycle_days',
    ];

    protected $casts = [
        // NULL means "use the app default" — see FeedingAtRiskRule::forInstitution().
        'feeding_at_risk_threshold' => 'integer',
        // How many recorded feeding days a learner must have before the
        // threshold classifies them at all. NULL is the app default too.
        'feeding_min_observation_days' => 'integer',
        // WHICH rule the school runs, not only the figure it is set to: a
        // school flagging after a week of unexcused absence is not running a
        // percentage at all.
        // The adviser's two days: the attendance notice (3) and the flag (7).
        'feeding_absence_notice_days' => 'integer',
        'feeding_absence_flag_days' => 'integer',
        // Retired with the removal review: nothing reads it, and no attendance
        // figure suggests taking a learner off the feeding line any more.
        'feeding_absence_removal_days' => 'integer',
        // 120 in Division policy, 90 under discussion — see FeedingProgramCycle.
        'feeding_cycle_days' => 'integer',
    ];

    public const DEFAULT_SCHOOLS = [
        'A. L. Navarro National High School',
        'Aurora Quebral Elementary School',
        'Baguio Central Elementary School',
        'Baguio National School of Arts and Trades',
        'Bala ES',
        'Balah Licosan Elementary School',
        'Baracatan National High School',
        'Bernardo D. Carpio National High School',
        'Biao National High School',
        'Binowang National High School',
        'Binugao National High School',
        'Buda National High School',
        'Cabagtukan ES',
        'Cabagbahangan Elementary School',
        'Cabantian National High School',
        'Calinan National High School',
        'Catigan National High School',
        'Congressman Manuel M. Garcia Elementary School',
        'Crossing Bayabas National High School',
        'Dacudao National High School',
        'Daniel R. Aguinaldo National High School',
        'Darila ES',
        'Datas Elementary School',
        'Datu Ansayod Elementary School',
        'Datu Timawa Elementary School',
        'Davao City National High School',
        'Davao City Special National High School',
        'Dominga ES',
        'Doña Carmen Denia National High School',
        'Dr. Santiago Dakudao Sr. National High School',
        'Dumalogdog E/S',
        'E. Ramos National High School',
        'Elias B. Lopez Memorial National High School',
        'Elias P. Dacudao Gumalang School of Home Industries',
        'Erico T. Nograres National High School',
        'F. Bangoy National High School',
        'F. Bustamante National High School',
        'Gorgonio Tajo, Sr. National High School',
        'J. V. Ferriols National High School',
        'Kidali ES',
        'Kiopao Elementary School',
        'Lamanan National High School',
        'Lorenzo Latawan National High School',
        'Ma. Cristina P. Belcar Agricultural High School',
        'Magtuod National High School',
        'Makatao Elementary School',
        'Maluan Elementary School',
        'New Tawas Elementary School',
        'Paraiso Elementary School',
        'Porferio L. Antipala National High School',
        'Salaysay National High School',
        'Saloy National High School',
        'T. Palma Elementary School',
        'Tacunan National High School',
        'Teofilo V. Fernandez National High School',
        'Tungkalan National High School',
        'Vicenta C. Nograles National High School',
        'Wangan National High School',
        'Wireless ES',
    ];

    /**
     * The school this deployment was built for: the one a demo session opens
     * on and the one the data migrations set policy for. It is one of
     * REGISTRATION_SCHOOLS, not the whole list.
     */
    public const REGISTRATION_SCHOOL = 'Sta. Ana National High School';

    /**
     * The only schools an account may be registered against.
     *
     * The registration form offers these and the server refuses every other
     * institution id. This is the single declaration of the list: never re-type
     * a name, and never widen the form to `Institution::active()`, since the
     * other rows in DEFAULT_SCHOOLS exist for the catalogue, not for sign-up.
     * Each school's records stay its own: every school-owned row carries its
     * `institution_id`, and a session reads only its own school's.
     *
     * @var list<string>
     */
    public const REGISTRATION_SCHOOLS = [
        self::REGISTRATION_SCHOOL,
        'Davao City National High School',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * The deployment's own school, or null when it is missing from the
     * database entirely.
     */
    public static function registrationSchool(): ?self
    {
        return self::query()
            ->where('name', self::REGISTRATION_SCHOOL)
            ->first();
    }

    /**
     * Every school registrations are allowed against that exists in the
     * database, in name order.
     *
     * @return Collection<int, self>
     */
    public static function registrationSchools(): Collection
    {
        return self::query()
            ->whereIn('name', self::REGISTRATION_SCHOOLS)
            ->orderBy('name')
            ->get();
    }

    public static function seedDefaults(): void
    {
        foreach (self::DEFAULT_SCHOOLS as $name) {
            self::firstOrCreate(['name' => $name], ['status' => 'active']);
        }

        // Every school a registration may name must exist, or the form would
        // offer an empty dropdown and refuse every account request. The others
        // are in the catalogue above; this repeats them so the list stays
        // complete if the catalogue ever changes.
        foreach (self::REGISTRATION_SCHOOLS as $name) {
            if ($name !== self::REGISTRATION_SCHOOL) {
                self::firstOrCreate(['name' => $name], ['status' => 'active']);
            }
        }

        // The school this system is deployed for is not in the catalogue above.
        //
        // It runs a week of unexcused absence rather than a share of the cycle,
        // which is the school's own policy and so lives on its row rather than
        // in config: a learner who attended every session for two months and
        // then vanished for a week is above 90% attended and needs following up
        // today. A migration fills the same value on a database seeded before
        // this line existed; neither ever overrules a mode already chosen
        // through the System Admin form.
        $school = self::firstOrCreate(
            ['name' => self::REGISTRATION_SCHOOL],
            ['status' => 'active', 'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS]
        );

        // Schistosomiasis, Filariasis and the TAS on Lymphatic Filariasis are
        // not given in Davao City, so the school's Health Services Offered
        // starts with them off — the same row a migration fills on a database
        // seeded before this line existed. A nurse's saved choice is never
        // overruled.
        if (HealthServicesOffered::supported()
            && ! HealthServiceSetting::query()->where('institution_id', $school->id)->exists()) {
            HealthServicesOffered::save(
                $school->id,
                ParentalConsentForm::calendarSchoolYear(),
                array_values(array_diff(
                    array_keys(HealthConsentForm::PLANNED_SERVICES),
                    ['deworming_schistosomiasis', 'deworming_filariasis', 'tas'],
                )),
                'System default',
            );
        }
    }
}
