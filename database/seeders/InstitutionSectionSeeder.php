<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\InstitutionSection;
use Illuminate\Database\Seeder;

/**
 * Each registration school's section list.
 *
 * Registration offers a class adviser a choice from this catalogue rather than
 * a text box. Sta. Ana National High School's list is its real one, every
 * name transcribed from "SECTION REPORT As of July 1", one column per grade
 * level. Davao City National High School has not sent its report, so its list
 * is a placeholder of invented names, to be replaced by the real one.
 *
 * A section belongs to one school: two schools may both run a "Grade 7 /
 * MABAIT" and never share a learner, because every record is scoped by the
 * school first and by the grade and section within it.
 *
 * Re-running is safe: rows are matched on (institution, grade, name), which is
 * the table's unique key.
 */
class InstitutionSectionSeeder extends Seeder
{
    public const DAVAO_CITY = 'Davao City National High School';

    /**
     * Placeholder sections for Davao City National High School: invented
     * names, five per grade, until the school publishes its own.
     *
     * @var array<string, list<string>>
     */
    public const DAVAO_CITY_SECTIONS = [
        'Grade 7' => ['GUMAMELA', 'ILANG-ILANG', 'ROSAL', 'SAMPAGUITA', 'WALING-WALING'],
        'Grade 8' => ['ACACIA', 'KAMAGONG', 'MOLAVE', 'NARRA', 'YAKAL'],
        'Grade 9' => ['DIAMOND', 'EMERALD', 'JADE', 'RUBY', 'SAPPHIRE'],
        'Grade 10' => ['JUPITER', 'MARS', 'MERCURY', 'SATURN', 'VENUS'],
    ];

    /** @var array<string, list<string>> */
    private const STA_ANA_SECTIONS = [
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
        foreach ([
            Institution::REGISTRATION_SCHOOL => self::STA_ANA_SECTIONS,
            self::DAVAO_CITY => self::DAVAO_CITY_SECTIONS,
        ] as $school => $sections) {
            $count = self::seedSchool($school, $sections);

            $this->command?->info("Seeded {$count} sections at \"{$school}\".");
        }
    }

    /**
     * Write one school's sections in a single statement, creating the school
     * if it is missing. Rows already there are left alone.
     *
     * @param  array<string, list<string>>  $sections
     * @return int the number of sections in the list
     */
    public static function seedSchool(string $school, array $sections): int
    {
        $institution = Institution::firstOrCreate(
            ['name' => $school],
            ['status' => 'active']
        );

        return self::seedSections((int) $institution->id, $sections);
    }

    /**
     * Write sections for a school that already exists, in one statement.
     *
     * @param  array<string, list<string>>  $sections
     * @return int the number of sections in the list
     */
    public static function seedSections(int $institutionId, array $sections): int
    {
        $now = now();
        $rows = [];

        foreach ($sections as $gradeLevel => $names) {
            foreach ($names as $name) {
                $rows[] = [
                    'institution_id' => $institutionId,
                    'grade_level' => $gradeLevel,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        InstitutionSection::query()->insertOrIgnore($rows);

        return count($rows);
    }
}
