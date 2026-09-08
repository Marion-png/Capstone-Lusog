<?php

namespace App\Models;

use App\Support\FeedingAtRiskRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
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
        'feeding_absence_flag_days' => 'integer',
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
     * The only school an account may be registered against.
     *
     * The system is deployed for one school, so the registration form offers
     * one choice and the server refuses every other institution id. This is
     * the single declaration of that name — never re-type it, and never widen
     * the registration form to `Institution::active()` again: the 59 other
     * rows in DEFAULT_SCHOOLS exist for the catalogue, not for sign-up.
     */
    public const REGISTRATION_SCHOOL = 'Sta. Ana National High School';

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * The one institution registrations are allowed against, or null when it
     * is missing from the database entirely.
     */
    public static function registrationSchool(): ?self
    {
        return self::query()
            ->where('name', self::REGISTRATION_SCHOOL)
            ->first();
    }

    public static function seedDefaults(): void
    {
        foreach (self::DEFAULT_SCHOOLS as $name) {
            self::firstOrCreate(['name' => $name], ['status' => 'active']);
        }

        // The school this system is deployed for is not in the catalogue above,
        // and it is the only school a registration may name — so a database
        // seeded from the defaults alone would otherwise offer an empty school
        // dropdown and refuse every account request.
        //
        // It runs a week of unexcused absence rather than a share of the cycle,
        // which is the school's own policy and so lives on its row rather than
        // in config: a learner who attended every session for two months and
        // then vanished for a week is above 90% attended and needs following up
        // today. A migration fills the same value on a database seeded before
        // this line existed; neither ever overrules a mode already chosen
        // through the System Admin form.
        self::firstOrCreate(
            ['name' => self::REGISTRATION_SCHOOL],
            ['status' => 'active', 'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS]
        );
    }
}
