{{--
    The School Nurse's learner search, for any nurse topbar.

    The search must be on every tab, not only the Dashboard — a nurse who
    has to go Home first to look somebody up will stop using it. So this
    builds its own roster rather than expecting a controller to pass one,
    and each nurse page includes it with a single line.

    The roster is App\Support\LearnerSearchIndex — synced from the database
    first (the invariant: any page reading `school_health_card_records`
    calls StudentRosterSync), then embedded and filtered in the browser,
    because student names are encrypted at rest and no SQL LIKE can see
    them. The New Consultation learner picker reads the same index.

    Nurse-only. The Health Records, Consultation Log and Medicine
    Inventory pages are shared with Clinic Staff, whose rail carries no
    search; this renders nothing for them rather than handing a role an
    affordance its navigation does not have.
--}}
@if (session('active_role') === 'school_nurse')
    @php
        $nurseSearchRoster = collect(\App\Support\LearnerSearchIndex::fromSession(request()));
    @endphp

    @include('partials.learner-search', [
        'roster' => $nurseSearchRoster,
        'hrefPattern' => route('dashboard.student-health-records').'?open={lrn}',
    ])
@endif
