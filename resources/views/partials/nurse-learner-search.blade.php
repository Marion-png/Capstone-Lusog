{{--
    The School Nurse's learner search, for any nurse topbar.

    The search must be on every tab, not only the Dashboard — a nurse who
    has to go Home first to look somebody up will stop using it. So this
    builds its own roster rather than expecting a controller to pass one,
    and each nurse page includes it with a single line.

    The roster is synced from the database first (the invariant: any page
    reading `school_health_card_records` calls StudentRosterSync), then
    embedded and filtered in the browser, because student names are
    encrypted at rest and no SQL LIKE can see them.

    Nurse-only. The Health Records, Consultation Log and Medicine
    Inventory pages are shared with Clinic Staff, whose rail carries no
    search; this renders nothing for them rather than handing a role an
    affordance its navigation does not have.
--}}
@if (session('active_role') === 'school_nurse')
    @php
        \App\Support\StudentRosterSync::syncToSession(request());

        $nurseSearchRoster = collect(session('school_health_card_records', []))
            ->map(function ($row) {
                $middle = trim((string) ($row['middle_name'] ?? ''));
                $name = trim(
                    trim((string) ($row['last_name'] ?? '')).', '.
                    trim((string) ($row['first_name'] ?? '')).
                    ($middle !== '' ? ' '.strtoupper(substr($middle, 0, 1)).'.' : '')
                );

                return [
                    'lrn' => (string) ($row['lrn'] ?? ''),
                    'name' => trim($name, ' ,'),
                    'section' => trim(trim((string) ($row['grade_level'] ?? '')).' - '.trim((string) ($row['section'] ?? '')), ' -'),
                ];
            })
            ->filter(fn (array $row) => $row['lrn'] !== '' && $row['name'] !== '')
            ->unique('lrn')
            ->sortBy('name')
            ->values();
    @endphp

    @include('partials.learner-search', [
        'roster' => $nurseSearchRoster,
        'hrefPattern' => route('dashboard.student-health-records').'?open={lrn}',
    ])
@endif
