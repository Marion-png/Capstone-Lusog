<?php

use App\Models\Institution;
use App\Models\ParentalConsentForm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The health services a school offers, per school year — the School Nurse's
 * "Health Services Offered" setting (App\Support\HealthServicesOffered).
 *
 * One row per school per school year, holding the services switched OFF, so a
 * service added to the Sulat-Pahibalo later is offered until somebody says it
 * is not. Nothing here is a fact about a person, so every column is plain and
 * may be used in SQL, except the name of whoever saved it, which is a staff
 * name and is encrypted by the model.
 *
 * The deployment's school, Sta. Ana National High School in Davao City, does
 * not give Schistosomiasis or Filariasis medicine and runs no TAS on Lymphatic
 * Filariasis — none is endemic there — so its row starts with those three off.
 * It only fills a school with no row of its own; a nurse's saved choice is
 * never overruled.
 */
return new class extends Migration
{
    private const STA_ANA_NOT_OFFERED = ['deworming_schistosomiasis', 'deworming_filariasis', 'tas'];

    public function up(): void
    {
        if (! Schema::hasTable('health_services_offered')) {
            Schema::create('health_services_offered', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
                $table->string('school_year', 9);
                $table->json('not_offered');
                $table->text('updated_by_name')->nullable();
                $table->timestamps();

                $table->unique(['institution_id', 'school_year']);
            });
        }

        if (! Schema::hasTable('institutions')) {
            return;
        }

        $school = DB::table('institutions')->where('name', Institution::REGISTRATION_SCHOOL)->value('id');

        if ($school && ! DB::table('health_services_offered')->where('institution_id', $school)->exists()) {
            DB::table('health_services_offered')->insert([
                'institution_id' => $school,
                'school_year' => ParentalConsentForm::calendarSchoolYear(),
                'not_offered' => json_encode(self::STA_ANA_NOT_OFFERED),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_services_offered');
    }
};
