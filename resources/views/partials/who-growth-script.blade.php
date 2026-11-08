{{--
    BMI-for-age and height-for-age in the browser, for the forms that preview
    a learner's status as the adviser types.

    It is App\Support\BmiClassifier and App\Support\WhoGrowthReference read
    again, not a second set of numbers: the WHO 2007 table below is the very
    one the server classifies with (WhoGrowthReference::forClient()), the
    labels are the server's constants, and the arithmetic is the same — age in
    months (days ÷ 30.4375), L/M/S interpolated between months, the restricted
    z beyond ±3 SD for BMI, two decimals. The server still recomputes on save,
    so the preview can never become the stored value. Each form used to carry
    its own thresholds, and no two of the three agreed.

    Include once per page, before the script that calls window.WhoGrowth.
--}}
@php
    $whoGrowthLabels = [
        'none' => \App\Support\BmiClassifier::NOT_ENOUGH_DATA,
        'sw' => \App\Support\BmiClassifier::SEVERELY_WASTED,
        'w' => \App\Support\BmiClassifier::WASTED,
        'n' => \App\Support\BmiClassifier::NORMAL,
        'ow' => \App\Support\BmiClassifier::OVERWEIGHT,
        'ob' => \App\Support\BmiClassifier::OBESE,
        'ss' => \App\Support\BmiClassifier::SEVERELY_STUNTED,
        'st' => \App\Support\BmiClassifier::STUNTED,
        'hn' => \App\Support\BmiClassifier::NORMAL_HEIGHT,
        't' => \App\Support\BmiClassifier::TALL,
    ];
    $whoGrowthLimits = [
        'bmiZ' => \App\Support\MeasurementPlausibility::BMI_Z_LIMIT,
        'heightZ' => \App\Support\MeasurementPlausibility::HEIGHT_Z_LIMIT,
        'minBmi' => \App\Support\MeasurementPlausibility::MIN_BMI,
        'maxBmi' => \App\Support\MeasurementPlausibility::MAX_BMI,
    ];
@endphp
<script>
window.WhoGrowth = window.WhoGrowth || (() => {
    const REF = @json(\App\Support\WhoGrowthReference::forClient());
    const MIN = {{ \App\Support\WhoGrowthReference::MIN_MONTHS }};
    const MAX = {{ \App\Support\WhoGrowthReference::MAX_MONTHS }};
    const DAYS_PER_MONTH = {{ \App\Support\WhoGrowthReference::DAYS_PER_MONTH }};
    const BMI_FOR_AGE = @json(\App\Support\WhoGrowthReference::BMI_FOR_AGE);
    const HEIGHT_FOR_AGE = @json(\App\Support\WhoGrowthReference::HEIGHT_FOR_AGE);
    const LABEL = @json($whoGrowthLabels);

    const sexCode = (gender) => {
        const g = String(gender || '').trim().toLowerCase();
        if (g.startsWith('m')) return 1;
        if (g.startsWith('f')) return 2;
        return null;
    };

    // Whole calendar days between two dates, whatever the clock says.
    const ageInMonths = (birth, on = new Date()) => {
        if (!(birth instanceof Date) || Number.isNaN(birth.getTime())) return null;
        const days = Math.round(
            (Date.UTC(on.getFullYear(), on.getMonth(), on.getDate())
                - Date.UTC(birth.getFullYear(), birth.getMonth(), birth.getDate())) / 86400000
        );
        return days < 0 ? null : days / DAYS_PER_MONTH;
    };

    const lms = (indicator, sex, age) => {
        const rows = REF[indicator] && REF[indicator][sex];
        if (!rows || age === null || age < MIN) return null;
        const a = Math.min(age, MAX);
        const low = Math.floor(a);
        const f = a - low;
        const lo = rows[low - MIN];
        const up = rows[low - MIN + 1] || lo;
        return [0, 1, 2].map((i) => lo[i] + f * (up[i] - lo[i]));
    };

    const valueAt = (l, m, s, z) => m * Math.pow(1 + l * s * z, 1 / l);

    const zScore = (indicator, gender, age, value) => {
        const sex = sexCode(gender);
        if (sex === null || !(value > 0)) return null;
        const p = lms(indicator, sex, age);
        if (!p) return null;
        const [l, m, s] = p;
        let z = (Math.pow(value / m, l) - 1) / (s * l);
        if (indicator === BMI_FOR_AGE) {
            if (z > 3) {
                const sd3 = valueAt(l, m, s, 3);
                z = 3 + (value - sd3) / (sd3 - valueAt(l, m, s, 2));
            } else if (z < -3) {
                const sd3 = valueAt(l, m, s, -3);
                z = -3 + (value - sd3) / (valueAt(l, m, s, -2) - sd3);
            }
        }
        return Math.sign(z) * Math.round(Math.abs(z) * 100) / 100;
    };

    const bmiForAge = (bmi, age, gender) => {
        const z = zScore(BMI_FOR_AGE, gender, age, bmi);
        if (z === null) return LABEL.none;
        if (z < -3) return LABEL.sw;
        if (z < -2) return LABEL.w;
        if (z <= 1) return LABEL.n;
        if (z <= 2) return LABEL.ow;
        return LABEL.ob;
    };

    const heightForAge = (heightCm, age, gender) => {
        const z = zScore(HEIGHT_FOR_AGE, gender, age, heightCm);
        if (z === null) return LABEL.none;
        if (z < -3) return LABEL.ss;
        if (z < -2) return LABEL.st;
        if (z <= 2) return LABEL.hn;
        return LABEL.t;
    };

    // App\Support\MeasurementPlausibility, read again: WHO's flags for a
    // reading no learner could have, with the server's own wording. The
    // server refuses the same figures on save; this only says so sooner.
    const LIMIT = @json($whoGrowthLimits);

    const figure = (value) => String(Number(value.toFixed(2)));

    const ageLabel = (months) => {
        const whole = Math.floor(months);
        const years = Math.floor(whole / 12);
        const rest = whole % 12;
        return years + (years === 1 ? ' year' : ' years') + (rest > 0 ? ' ' + rest + (rest === 1 ? ' month' : ' months') : '');
    };

    const problems = (heightCm, weightKg, age, gender) => {
        const found = { height: '', weight: '' };
        const h = heightCm > 0 ? heightCm : null;
        const w = weightKg > 0 ? weightKg : null;

        if (h !== null) {
            const z = zScore(HEIGHT_FOR_AGE, gender, age, h);
            if (z !== null && Math.abs(z) > LIMIT.heightZ) {
                found.height = 'A height of ' + figure(h) + ' cm is not possible for a learner aged '
                    + ageLabel(age) + '. Check the height and the date of birth.';
            }
        }

        if (h !== null && w !== null) {
            const bmi = w / Math.pow(h / 100, 2);
            const z = zScore(BMI_FOR_AGE, gender, age, Math.round(bmi * 100) / 100);
            const implausible = z !== null ? Math.abs(z) > LIMIT.bmiZ : (bmi < LIMIT.minBmi || bmi > LIMIT.maxBmi);
            if (implausible) {
                found.weight = 'A height of ' + figure(h) + ' cm and a weight of ' + figure(w) + ' kg give a BMI of '
                    + bmi.toFixed(1) + ', which is not possible for this learner. Check both measurements.';
            }
        }

        return found;
    };

    return { ageInMonths, bmiForAge, heightForAge, problems };
})();
</script>
