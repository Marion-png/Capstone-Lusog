<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Sheet 2 of the Mandatory Learner's Health Assessment Tool — Systems Review,
 * Screenings, and Recommendations — as one structure.
 *
 * The clinic's workbook lays Sheet 2 out as five lettered sections: F. the
 * twelve body systems (finding + notes each), G. vision and hearing, H. oral
 * health, I. immunization, J. summary and recommendations. The nurse fills
 * that sheet on the Fill Medical Record form, the profile's Sheet 2 tab shows
 * it, and the MLAT download writes it — and all three read **this** class, so
 * the form a nurse fills, the tab a nurse reads and the file the clinic files
 * cannot lay the same sheet out three ways.
 *
 * It is stored on the nurse's own `examination` column as `sheet2`. Where the
 * nurse has not filled it yet, `read()` derives a first draft from what is
 * already on file — the adviser's Sheet 2 checklist (`systems_review`) and the
 * older examination fields — so the form opens with the record's answers
 * rather than blank, and a reading nobody took stays blank.
 */
class Sheet2Review
{
    /** F. The body systems, in the sheet's order. */
    public const SYSTEMS = [
        'integumentary' => 'Integumentary',
        'heent_head' => 'HEENT – Head/Scalp',
        'heent_eyes' => 'HEENT – Eyes',
        'heent_ears' => 'HEENT – Ears',
        'heent_nose' => 'HEENT – Nose',
        'heent_throat' => 'HEENT – Throat',
        'respiratory' => 'Respiratory',
        'cardiovascular' => 'Cardiovascular',
        'gastrointestinal' => 'Gastrointestinal',
        'genitourinary' => 'Genitourinary',
        'musculoskeletal' => 'Musculoskeletal',
        'neurological' => 'Neurological',
    ];

    /**
     * F. Each system's findings, as the MLAT Sheet 2 template lists them
     * under "Findings (Check / Status)". The first is the system's normal
     * finding; the rest are what the template names as wrong with it.
     *
     * @var array<string, list<string>>
     */
    public const FINDINGS = [
        'integumentary' => ['Normal', 'Lesions/Rashes', 'Pallor', 'Other'],
        'heent_head' => ['Normal', 'Abnormal'],
        'heent_eyes' => ['Clear', 'Redness', 'Discharge'],
        'heent_ears' => ['Clear', 'Pain', 'Discharge'],
        'heent_nose' => ['Clear', 'Congested'],
        'heent_throat' => ['Normal', 'Inflamed', 'Tonsillar Issues'],
        'respiratory' => ['Clear Breath Sounds', 'Cough', 'Wheezing'],
        'cardiovascular' => ['Regular Rhythm', 'Irregular', 'Murmur'],
        'gastrointestinal' => ['Abdomen Soft', 'Pain', 'Nausea/Vomiting'],
        'genitourinary' => ['No Complaints', 'Pain', 'Other'],
        'musculoskeletal' => ['Normal ROM', 'Deformity', 'Pain'],
        'neurological' => ['Oriented', 'Reflexes Normal', 'Abnormal'],
    ];

    /** G. The screening outcomes — a vision result is one of two, a hearing result one of four. */
    public const VISION_RESULTS = ['Pass', 'Refer'];

    public const HEARING_RESULTS = ['Passed Both', 'Failed Right', 'Failed Left', 'Refer'];

    /** H. The teeth conditions the template lists. */
    public const TEETH = ['Good', 'Fair', 'Poor', 'Dental Caries', 'Gum Inflammation', 'Missing/Broken Teeth'];

    /** H. Whether the learner was sent on for dental care. */
    public const DENTAL_REFERRALS = ['No referral required', 'Referred for dental care'];

    public const IMMUNIZATION = ['Complete', 'Incomplete', 'Not available'];

    /** The key the nurse's sheet is stored under on `examination`. */
    public const KEY = 'sheet2';

    /**
     * The sheet as filled, or as derived from the record where it is not.
     *
     * @param  array<string, mixed>  $examination  the nurse's examination column
     * @param  array<string, mixed>  $systemsReview  the adviser's Sheet 2 checklist
     * @return array{
     *   source: string,
     *   systems: array<string, array{label: string, finding: string, notes: string}>,
     *   vision: array{right: string, left: string, result: string},
     *   hearing: array{result: string},
     *   oral: array{teeth: string, last_visit: string, referral: string},
     *   immunization: array{status: string, missing: string, reviewed_at: string},
     *   summary: array{findings: string, recommendations: string, examiner: string, date: string}
     * }
     */
    public static function read(array $examination, array $systemsReview = []): array
    {
        $stored = $examination[self::KEY] ?? null;

        if (is_array($stored) && $stored !== []) {
            return self::normalize($stored) + ['source' => 'nurse'];
        }

        return self::derive($examination, $systemsReview);
    }

    /**
     * The sheet as the form posted it, reduced to its known keys and options.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromInput(array $input): array
    {
        $systems = [];
        foreach (array_keys(self::SYSTEMS) as $key) {
            $row = is_array($input['systems'][$key] ?? null) ? $input['systems'][$key] : [];
            $systems[$key] = [
                'finding' => self::option($row['finding'] ?? '', self::findingsFor($key)),
                'notes' => self::text($row['notes'] ?? ''),
            ];
        }

        return [
            'systems' => $systems,
            'vision' => [
                'right' => self::text($input['vision_right'] ?? ''),
                'left' => self::text($input['vision_left'] ?? ''),
                'result' => self::option($input['vision_result'] ?? '', self::VISION_RESULTS),
            ],
            'hearing' => ['result' => self::option($input['hearing_result'] ?? '', self::HEARING_RESULTS)],
            'oral' => [
                'teeth' => self::option($input['teeth_condition'] ?? '', self::TEETH),
                'last_visit' => self::text($input['last_dental_visit'] ?? ''),
                'referral' => self::option($input['dental_referral'] ?? '', self::DENTAL_REFERRALS),
            ],
            'immunization' => [
                'status' => self::option($input['immunization_status'] ?? '', self::IMMUNIZATION),
                'missing' => self::text($input['missing_vaccines'] ?? ''),
                'reviewed_at' => self::date($input['immunization_reviewed_at'] ?? ''),
            ],
            'summary' => [
                'findings' => self::text($input['summary_findings'] ?? '', 2000),
                'recommendations' => self::text($input['recommendations'] ?? '', 2000),
                // Examiner and date are the app's, set by the caller.
                'examiner' => self::text($input['examiner'] ?? ''),
                'date' => self::date($input['examiner_date'] ?? ''),
            ],
        ];
    }

    /**
     * A stored sheet, with every key present and every option valid — a sheet
     * saved under an older shape still reads.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function normalize(array $stored): array
    {
        $sheet = self::fromInput([
            'systems' => self::legacySystems(is_array($stored['systems'] ?? null) ? $stored['systems'] : []),
            'vision_right' => $stored['vision']['right'] ?? '',
            'vision_left' => $stored['vision']['left'] ?? '',
            'vision_result' => $stored['vision']['result'] ?? '',
            'hearing_result' => $stored['hearing']['result'] ?? '',
            'teeth_condition' => $stored['oral']['teeth'] ?? '',
            'last_dental_visit' => $stored['oral']['last_visit'] ?? '',
            'dental_referral' => $stored['oral']['referral'] ?? '',
            'immunization_status' => $stored['immunization']['status'] ?? '',
            'missing_vaccines' => $stored['immunization']['missing'] ?? '',
            'immunization_reviewed_at' => $stored['immunization']['reviewed_at'] ?? '',
            'summary_findings' => $stored['summary']['findings'] ?? '',
            'recommendations' => $stored['summary']['recommendations'] ?? '',
            'examiner' => $stored['summary']['examiner'] ?? '',
            'examiner_date' => $stored['summary']['date'] ?? '',
        ]);

        return self::labelled($sheet);
    }

    /**
     * A first draft from the adviser's checklist and the older examination
     * fields, for a learner whose nurse's sheet has not been filled yet.
     *
     * @param  array<string, mixed>  $exam
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private static function derive(array $exam, array $review): array
    {
        $on = fn (string $key): bool => (bool) ($review[$key] ?? false);
        $text = fn (mixed $value): string => self::text($value);

        // Each draft lands on one of the system's own findings, or stays blank
        // for the nurse to decide. The adviser's checklist marks HEENT as a
        // whole, so an abnormal HEENT can say "Abnormal" only for the head —
        // the one HEENT row that has the word — and leaves the eyes, ears,
        // nose and throat for the examination to name.
        $skin = match (true) {
            $on('skin_lesions') => 'Lesions/Rashes',
            $on('skin_pallor') => 'Pallor',
            $on('skin_normal') => 'Normal',
            default => '',
        };
        $skinNotes = implode(', ', array_filter([
            $on('skin_lesions') ? 'Lesions / rashes' : '',
            $on('skin_pallor') ? 'Pallor' : '',
            $text($exam['skin_scalp'] ?? ''),
        ]));
        $heentNormal = $on('heent_normal') && ! $on('heent_abnormal');
        $heentFinding = fn (string $system): string => $heentNormal ? self::normalFinding($system) : '';
        $resp = $on('resp_cough') ? 'Cough' : ($on('resp_clear') ? 'Clear Breath Sounds' : '');
        $cardio = $on('cardio_irregular') ? 'Irregular' : ($on('cardio_regular') ? 'Regular Rhythm' : '');
        $abdo = $on('abdo_pain') ? 'Pain' : ($on('abdo_soft') ? 'Abdomen Soft' : '');
        $neuro = match (true) {
            $on('neuro_abnormal') => 'Abnormal',
            $on('neuro_alert') => 'Oriented',
            $on('neuro_reflexes') => 'Reflexes Normal',
            default => '',
        };

        $systems = [
            'integumentary' => ['finding' => $skin, 'notes' => $skinNotes],
            'heent_head' => ['finding' => $on('heent_abnormal') ? 'Abnormal' : $heentFinding('heent_head'), 'notes' => ''],
            'heent_eyes' => ['finding' => $heentFinding('heent_eyes'), 'notes' => $text($exam['eyes_ears_nose'] ?? '')],
            'heent_ears' => ['finding' => $heentFinding('heent_ears'), 'notes' => ''],
            'heent_nose' => ['finding' => $heentFinding('heent_nose'), 'notes' => ''],
            'heent_throat' => ['finding' => $heentFinding('heent_throat'), 'notes' => $text($exam['mouth_throat_neck'] ?? '')],
            'respiratory' => ['finding' => $resp, 'notes' => implode(', ', array_filter([$on('resp_cough') ? 'Cough' : '', $text($exam['lungs_heart'] ?? '')]))],
            'cardiovascular' => ['finding' => $cardio, 'notes' => $on('cardio_irregular') ? 'Irregular rhythm' : ''],
            'gastrointestinal' => ['finding' => $abdo, 'notes' => implode(', ', array_filter([$on('abdo_pain') ? 'Pain / tenderness' : '', $text($exam['abdomen'] ?? '')]))],
            'genitourinary' => ['finding' => '', 'notes' => ''],
            'musculoskeletal' => ['finding' => '', 'notes' => $text($exam['deformities'] ?? '')],
            'neurological' => ['finding' => $neuro, 'notes' => ''],
        ];

        // The grade the adviser ticked, else the finding: a mouth marked only
        // for caries or gum inflammation still opens on what was found.
        $teeth = match (true) {
            $on('dental_poor') => 'Poor',
            $on('dental_fair') => 'Fair',
            $on('dental_good') => 'Good',
            $on('dental_caries') => 'Dental Caries',
            $on('dental_gum') => 'Gum Inflammation',
            default => '',
        };
        $dentalNotes = implode(', ', array_filter([$on('dental_caries') ? 'Caries' : '', $on('dental_gum') ? 'Gum disease' : '']));

        $immunization = match (true) {
            $on('immun_complete') => 'Complete',
            $on('immun_incomplete') => 'Incomplete',
            $on('immun_not_available') => 'Not available',
            default => '',
        };

        $sheet = [
            'systems' => $systems,
            'vision' => [
                'right' => $text($review['right_eye'] ?? ''),
                'left' => $text($review['left_eye'] ?? ''),
                'result' => self::screeningResult($exam['vision_screening'] ?? '', self::VISION_RESULTS),
            ],
            'hearing' => ['result' => self::screeningResult($exam['auditory_screening'] ?? '', self::HEARING_RESULTS)],
            'oral' => [
                'teeth' => $teeth,
                'last_visit' => '',
                // One of the two options, never a sentence: the field is a
                // dropdown now, and a draft that filled it with prose would
                // arrive at a control that cannot show it. What the adviser
                // ticked (caries, gum disease) is already on the body-systems
                // rows above, so nothing is lost by dropping the suffix.
                'referral' => $on('dental_referral')
                    ? 'Referred for dental care'
                    : ($teeth !== '' ? 'No referral required' : ''),
            ],
            'immunization' => [
                'status' => $immunization,
                'missing' => $immunization === 'Incomplete' ? $text($exam['immunization'] ?? '') : '',
                'reviewed_at' => self::date($review['immun_date'] ?? ''),
            ],
            'summary' => [
                'findings' => $text($review['summary'] ?? '') !== '' ? $text($review['summary']) : $text($review['notes'] ?? ''),
                'recommendations' => $text($review['recommendations'] ?? ''),
                'examiner' => $text($review['examiner_name'] ?? '') !== '' ? $text($review['examiner_name']) : $text($exam['examined_by'] ?? ''),
                'date' => self::date($review['examiner_date'] ?? ($exam['date_of_examination'] ?? '')),
            ],
        ];

        $filled = array_filter($review) !== [] || array_filter($exam) !== [];

        return self::labelled($sheet) + ['source' => $filled ? 'adviser' : 'none'];
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @return array<string, mixed>
     */
    private static function labelled(array $sheet): array
    {
        foreach (self::SYSTEMS as $key => $label) {
            $sheet['systems'][$key] = ($sheet['systems'][$key] ?? ['finding' => '', 'notes' => '']) + ['label' => $label];
        }

        return $sheet;
    }

    /** One system's findings, as the template lists them. @return list<string> */
    public static function findingsFor(string $system): array
    {
        return self::FINDINGS[$system] ?? [];
    }

    /** The system's normal finding — the first the template lists. */
    public static function normalFinding(string $system): string
    {
        return self::FINDINGS[$system][0] ?? '';
    }

    /** A finding other than the system's normal one; a blank finding is neither. */
    public static function isAbnormal(string $system, string $finding): bool
    {
        $finding = trim($finding);

        return $finding !== '' && strcasecmp($finding, self::normalFinding($system)) !== 0;
    }

    /**
     * A sheet saved when every system was answered Normal or Abnormal, read
     * onto the template's own findings.
     *
     * Normal is the system's normal finding. Abnormal is a finding only where
     * the system lists the word; elsewhere it no longer names one of the
     * system's findings, so it is kept in the notes — what the nurse recorded
     * is never dropped — and the finding is left for the nurse to choose.
     *
     * @param  array<string, mixed>  $systems
     * @return array<string, mixed>
     */
    private static function legacySystems(array $systems): array
    {
        foreach ($systems as $key => $row) {
            if (! is_array($row) || ! isset(self::FINDINGS[$key])) {
                continue;
            }

            $finding = self::text($row['finding'] ?? '');
            if ($finding === '' || self::option($finding, self::FINDINGS[$key]) !== '') {
                continue;
            }

            if (strcasecmp($finding, 'Normal') === 0) {
                $systems[$key]['finding'] = self::normalFinding($key);
            } elseif (strcasecmp($finding, 'Abnormal') === 0) {
                $notes = self::text($row['notes'] ?? '');
                $systems[$key]['finding'] = '';
                $systems[$key]['notes'] = $notes === '' ? 'Abnormal' : 'Abnormal — '.$notes;
            }
        }

        return $systems;
    }

    private static function text(mixed $value, int $max = 500): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }

    /**
     * A screening result typed on the older free-text form, read onto the
     * sheet's own options: "passed" is a pass, "failed"/"referred" a
     * referral, and anything the options do not name stays blank for the
     * nurse to decide — a draft never invents an outcome.
     *
     * @param  list<string>  $options
     */
    private static function screeningResult(mixed $value, array $options): string
    {
        $exact = self::option($value, $options);
        if ($exact !== '') {
            return $exact;
        }

        $value = mb_strtolower(self::text($value));
        if ($value === '') {
            return '';
        }

        $pass = $options === self::HEARING_RESULTS ? 'Passed Both' : 'Pass';

        return match (true) {
            str_starts_with($value, 'pass') => $pass,
            str_starts_with($value, 'fail'), str_starts_with($value, 'refer') => 'Refer',
            default => '',
        };
    }

    /** @param  list<string>  $options */
    private static function option(mixed $value, array $options): string
    {
        $value = self::text($value);

        foreach ($options as $option) {
            if (strcasecmp($option, $value) === 0) {
                return $option;
            }
        }

        return '';
    }

    private static function date(mixed $value): string
    {
        $text = self::text($value);
        if ($text === '') {
            return '';
        }

        try {
            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
