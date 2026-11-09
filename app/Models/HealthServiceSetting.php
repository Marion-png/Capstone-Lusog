<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\HealthServicesOffered;
use Illuminate\Database\Eloquent\Model;

/**
 * One school's "Health Services Offered" for one school year: the services the
 * School Nurse switched off. App\Support\HealthServicesOffered is the one
 * reading of these rows.
 */
class HealthServiceSetting extends Model
{
    use Auditable;

    protected $table = 'health_services_offered';

    protected $fillable = ['institution_id', 'school_year', 'not_offered', 'updated_by_name'];

    protected function casts(): array
    {
        return [
            'not_offered' => 'array',
            // Who saved it is a staff name, encrypted like every other one.
            'updated_by_name' => EncryptedString::class,
        ];
    }

    protected static function booted(): void
    {
        // Read once per request; a save in that request has moved it.
        static::saved(static fn () => HealthServicesOffered::forget());
        static::deleted(static fn () => HealthServicesOffered::forget());
    }
}
