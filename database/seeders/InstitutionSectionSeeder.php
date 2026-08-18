<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\InstitutionSection;
use Illuminate\Database\Seeder;

/**
 * The partner school's own section list, as published on its section report.
 *
 * Registration offers a class adviser a choice from this catalogue rather than
 * a text box, so it has to be the school's real list — every name below is
 * transcribed from "SECTION REPORT As of July 1", one column per grade level.
 *
 * Re-running is safe: rows are matched on (institution, grade, name), which is
 * the table's unique key.
 */
class InstitutionSectionSeeder extends Seeder
{
    private const SCHOOL = 'Sta. Ana National High School';

    /** @var array<string, list<string>> */
    private const SECTIONS = [
        'Grade 7' => [
            'MAAASAHAN', 'MAABILIDAD', 'MAAWAIN', 'MAAYOS', 'MABAIT', 'MABIYAYA',
            'MADASALIN', 'MADUNONG', 'MAGALANG', 'MAGILIW', 'MAGITING', 'MAHINAHON',
            'MAHUSAY', 'MAINGAT', 'MAKATARUNGAN', 'MAKATUWIRAN', 'MALIKHAIN',
            'MAPAGBIGAY', 'MAPAGKATIWALAAN', 'MAPAGKUMBABA', 'MAPAGMAHAL',
            'MAPAGPASALAMAT', 'MAPAGPASENSYA', 'MAPAGPATAWAD', 'MAPAYAPA',
            'MAPRINSIPYO', 'MASINOP', 'MASIPAG', 'MASUNURIN', 'MATAGUMPAY',
            'MATALINO', 'MATAPAT', 'MATATAG', 'MATIYAGA', 'MATULUNGIN',
            'MAUNAWAIN', 'MAUNLAD',
        ],
        'Grade 8' => [
            'BIRDS OF PARADISE', 'BLUEBIRD', 'BUTTONQUAIL', 'CANARY', 'COCKATOO',
            'CRANE', 'DOVE', 'EAGLE', 'FALCON', 'FALCONET', 'FANTAILS', 'FINCHES',
            'FLAMINGO', 'FROGMOUTH', 'GOLDFINCH', 'HAWK', 'HERON', 'HORNBILL',
            'HUMMINGBIRD', 'MYNA', 'ORIOLES', 'PELICAN', 'PENGUIN', 'PHIL DUCK',
            'PITTAS', 'ROBIN', 'SHRIKE', 'SPARROW', 'SPIDER HUNTER', 'STORK',
            'SUNBIRD', 'SWAN', 'TROGON', 'WOODPECKER',
        ],
        'Grade 9' => [
            'ARCHIMEDES', 'ARISTOTLE', 'BABBAGE', 'BOYLE', 'CONFUCIUS', 'CURIE',
            'DARWIN', 'DA VINCI', 'DESCARTES', 'DEWEY', 'EINSTEIN', 'EUCLID',
            'FARADAY', 'FIBONACCI', 'FLEMING', 'FLOURENS', 'GALILEI', 'GRAHAM BELL',
            'HAWKING', 'JENNER', 'KEPLER', 'MAGNUS', 'MARCONI', 'MENDEL', 'MORSE',
            'NOBEL', 'PASCAL', 'PASTEUR', 'PLATO', 'PTOLEMY', 'PYTHAGORAS',
            'SOCRATES', 'TESLA', 'WATSON', 'WATT', 'WRIGHT',
        ],
        'Grade 10' => [
            'ABAD SANTOS', 'AGUINALDO', 'AQUINO', 'BALTAZAR', 'BONIFACIO', 'BURGOS',
            'CALDERON', 'DAGOHOY', 'DATU BAGO', 'DE JESUS', 'DEL PILAR',
            'DIEGO SILANG', 'DIZON', 'FELIPE', 'FLORENTINO', 'GUERRERO', 'JACINTO',
            'LAKANDULA', 'LAPU-LAPU', 'LUNA', 'MABINI', 'MALVAR', 'PALMA', 'PONCE',
            'RIZAL', 'SULAYMAN', 'TECSON', 'URDUJA', 'ZAMORA',
        ],
    ];

    public function run(): void
    {
        $institution = Institution::firstOrCreate(
            ['name' => self::SCHOOL],
            ['status' => 'active']
        );

        $count = 0;

        foreach (self::SECTIONS as $gradeLevel => $names) {
            foreach ($names as $name) {
                InstitutionSection::firstOrCreate([
                    'institution_id' => $institution->id,
                    'grade_level' => $gradeLevel,
                    'name' => $name,
                ]);
                $count++;
            }
        }

        $this->command?->info("Seeded {$count} sections at \"{$institution->name}\".");
    }
}
