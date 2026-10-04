{{--
    Nutritional Health Status — the nurse's one nutrition module, two views.

    These were two rail entries ("Nutritional Health Status" and, under Health
    Programs, "Nutritional Status Report") and they are not the same reading:
    one is every learner's weigh-ins, the other is the feeding programme's
    cycle, turnout and at-risk list. Two entries beside each other with nearly
    the same name is how a nurse learns to guess which one to press, so they
    are one module now — and the two readings stay, on a rail inside it,
    because deleting either would delete a figure somebody relies on.

    Both pages include this and pass `$active`. The pages keep their own
    routes: the rail is the merge, not a rewrite of either screen.
--}}
@php
    // The School Nurse and the Clinic Teacher open the same two readings;
    // each role's own path keeps EnsureActiveSession from re-seeding the
    // session as the other one.
    $nutritionPrefix = session('active_role') === 'clinic_teacher'
        ? 'dashboard.clinic-teacher'
        : 'dashboard.school-nurse';

    $nutritionTabs = [
        'learners' => [
            'label' => 'Learners',
            'hint' => 'Every learner, baseline against endline',
            'url' => route($nutritionPrefix.'.nutritional-status'),
        ],
        'programme' => [
            'label' => 'Feeding Programme',
            'hint' => 'Cycle, turnout and at-risk beneficiaries',
            'url' => route($nutritionPrefix.'.feeding-program'),
        ],
    ];
@endphp

<nav class="nh-tabs" aria-label="Nutritional Health Status views">
    @foreach ($nutritionTabs as $key => $tab)
        <a href="{{ $tab['url'] }}"
           class="nh-tab {{ ($active ?? 'learners') === $key ? 'active' : '' }}"
           @if (($active ?? 'learners') === $key) aria-current="page" @endif>
            <span class="nh-tab-label">{{ $tab['label'] }}</span>
            <span class="nh-tab-hint">{{ $tab['hint'] }}</span>
        </a>
    @endforeach
</nav>
