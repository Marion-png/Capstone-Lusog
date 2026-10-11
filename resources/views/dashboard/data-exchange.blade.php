<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Data Exchange - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/data-exchange.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.nurse-lusog-sidebar', ['active' => 'exchange'])

@php
    // The shared status scale, as on the Nutritional Health Status list.
    $fxStatusBadge = fn (string $status) => match ($status) {
        'Severely Wasted' => 'badge-critical',
        'Wasted', 'Underweight' => 'badge-risk',
        'Normal' => 'badge-normal',
        'Overweight', 'Obese' => 'badge-monitor',
        default => 'badge-neutral',
    };
    $serverTone = $server['secure'] ? 'ok' : ($server['configured'] ? 'bad' : 'off');
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Health Data Exchange</span></div>
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ session('active_school_name', 'No school assigned') }} &middot; SY {{ \App\Models\StudentHealthRecord::currentSchoolYear() }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        <div class="page-header">
            <div class="page-eyebrow">Interoperability</div>
            <h1 class="page-title">Health Data <span>Exchange</span></h1>
            <p class="page-sub">HL7 FHIR R4 &middot; transaction bundles over HTTPS</p>
        </div>

        <section class="card fx-server is-{{ $serverTone }}" aria-label="Receiving server">
            <div class="fx-server-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></svg>
            </div>
            <div class="fx-server-main">
                <div class="fx-server-label">Receiving Server</div>
                <div class="fx-server-url">{{ $server['configured'] ? ($server['url'] ?: $server['host']) : 'Not configured' }}</div>
            </div>
            <dl class="fx-server-facts">
                <div>
                    <dt>Transport</dt>
                    <dd>
                        @if ($server['secure'])
                            <span class="badge badge-normal">HTTPS &middot; TLS 1.2+</span>
                        @elseif ($server['configured'])
                            <span class="badge badge-critical">Not HTTPS &mdash; sending refused</span>
                        @else
                            <span class="badge badge-monitor">FHIR_ENDPOINT is empty</span>
                        @endif
                    </dd>
                </div>
                <div><dt>Credentials</dt><dd>{{ $server['auth'] === 'None' ? 'None' : $server['auth'] }}</dd></div>
                <div>
                    <dt>Disclosure</dt>
                    <dd title="{{ $server['deidentified'] ? 'No name, no LRN, birth year only' : 'Name, LRN and birth date' }}">{{ $server['deidentified'] ? 'Pseudonymised' : 'Identified' }}</dd>
                </div>
                <div><dt>Auto-send</dt><dd>{{ $server['auto'] ? 'On examination' : 'Off' }}</dd></div>
            </dl>
        </section>

        <div class="kpi-grid">
            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Transmitted</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($counts['sent']) }}</div>
                <div class="kpi-hint">{{ $counts['last_sent'] ? 'Last '.$counts['last_sent']->format('M j, Y g:i A') : 'None yet' }}</div>
            </div>

            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Learners Sent</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($counts['learners_sent']) }}</div>
                <div class="kpi-hint">of {{ number_format($learners->count()) }} on the roll</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Not Yet Sent</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($counts['learners_unsent']) }}</div>
                <div class="kpi-hint">This school year</div>
            </div>

            <div class="card kpi {{ $counts['failed'] > 0 ? 'accent-danger' : 'accent-info' }}">
                <div class="kpi-top">
                    <div class="kpi-label">Failed or Refused</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($counts['failed']) }}</div>
                <div class="kpi-hint">Not delivered</div>
            </div>
        </div>

        <section class="fx-section">
            <div class="table-card">
                <div class="fx-card-head">
                    <div>
                        <div class="card-title">Learners</div>
                        <div class="card-sub">{{ number_format($learners->count()) }} on this school year's roll</div>
                    </div>
                    <div class="fx-card-tools">
                        <div class="fx-seg" role="group" aria-label="Filter learners">
                            <button type="button" class="is-active" data-filter="all" aria-pressed="true">All <span class="fx-seg-count">{{ number_format($learners->count()) }}</span></button>
                            <button type="button" data-filter="unsent" aria-pressed="false">Not Yet Sent <span class="fx-seg-count">{{ number_format($counts['learners_unsent']) }}</span></button>
                            <button type="button" data-filter="sent" aria-pressed="false">Sent <span class="fx-seg-count">{{ number_format($counts['learners_sent']) }}</span></button>
                        </div>
                        <div class="lg-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="fxSearch" placeholder="Name, LRN, grade or section" autocomplete="off" aria-label="Search learners">
                        </div>
                    </div>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Learner</th>
                                <th>Grade &amp; Section</th>
                                <th>Nutritional Status</th>
                                <th>Last Sent</th>
                                <th>Patient ID</th>
                            </tr>
                        </thead>
                        <tbody id="fxLearners">
                            @forelse ($learners as $learner)
                                @php $gradeSection = trim($learner['grade'].($learner['section'] !== '' ? ' — '.$learner['section'] : '')); @endphp
                                <tr class="js-fx-row"
                                    data-sent="{{ $learner['transmission'] ? '1' : '0' }}"
                                    data-search="{{ mb_strtolower($learner['name'].' '.$learner['lrn'].' '.$gradeSection.' '.($learner['patient']['id'] ?? '')) }}">
                                    <td class="fx-name is-link">
                                        <a href="{{ route('dashboard.school-nurse.data-exchange.preview', $learner['lrn']) }}">
                                            {{ $learner['name'] !== '' ? $learner['name'] : $learner['lrn'] }}
                                            <span class="fx-name-sub">LRN {{ $learner['lrn'] }}</span>
                                        </a>
                                    </td>
                                    <td>{{ $gradeSection !== '' ? $gradeSection : '—' }}</td>
                                    <td><span class="badge {{ $fxStatusBadge($learner['status']) }}">{{ $learner['status'] !== '' ? $learner['status'] : 'Not measured' }}</span></td>
                                    <td class="tnum fx-nowrap">
                                        @if ($learner['last_sent'])
                                            {{ $learner['last_sent']->format('M j, Y') }}
                                            <span class="fx-time">{{ $learner['last_sent']->format('g:i A') }}</span>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                    <td class="fx-nowrap">
                                        @if ($learner['patient'])
                                            <a class="fx-id is-link" href="{{ route('dashboard.school-nurse.data-exchange.transmission', $learner['transmission']) }}" title="{{ $learner['patient']['url'] }}">{{ $learner['patient']['id'] }}</a>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="table-empty">No learners on this school year's roll.</td></tr>
                            @endforelse
                            <tr id="fxNoMatch" hidden><td colspan="5" class="table-empty">No learner matches your search.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="fx-card-foot" id="fxCount">{{ number_format($learners->count()) }} {{ $learners->count() === 1 ? 'learner' : 'learners' }}</div>
            </div>
        </section>

        <section class="fx-section">
            <div class="table-card">
                <div class="fx-card-head">
                    <div>
                        <div class="card-title">Transmission Log</div>
                        <div class="card-sub">Most recent first</div>
                    </div>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Learner</th>
                                <th>Patient ID</th>
                                <th>Server</th>
                                <th class="num">Resources</th>
                                <th>Status</th>
                                <th class="num">HTTP</th>
                                <th>Sent By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($transmissions as $transmission)
                                @php
                                    $when = $transmission->sent_at ?? $transmission->created_at;
                                    $logPatient = $transmission->patientOutcome();
                                @endphp
                                <tr>
                                    <td class="fx-name is-link tnum">
                                        <a href="{{ route('dashboard.school-nurse.data-exchange.transmission', $transmission) }}">
                                            {{ $when?->format('M j, Y') }}
                                            <span class="fx-name-sub is-plain">{{ $when?->format('g:i:s A') }} &middot; #{{ $transmission->id }}</span>
                                        </a>
                                    </td>
                                    <td>{{ $names[$transmission->student_lrn] ?? $transmission->student_lrn }}</td>
                                    <td class="fx-nowrap">
                                        @if ($logPatient)
                                            <span class="fx-id" title="{{ $logPatient['url'] }}">{{ $logPatient['id'] }}</span>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $transmission->endpoint_host ?: '—' }}</td>
                                    <td class="num">{{ $transmission->resource_count }}</td>
                                    <td>@include('partials.fhir-status-badge', ['status' => $transmission->status])</td>
                                    <td class="num">{{ $transmission->http_status ?: '—' }}</td>
                                    <td>{{ $transmission->sent_by_name ?: ($transmission->trigger === 'console' ? 'Console' : '—') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="table-empty">Nothing has been transmitted yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

@include('partials.nurse-page-transition')

<script>
(() => {
    const search = document.getElementById('fxSearch');
    const body = document.getElementById('fxLearners');
    if (!search || !body) return;

    const rows = Array.from(body.querySelectorAll('.js-fx-row'));
    const none = document.getElementById('fxNoMatch');
    const count = document.getElementById('fxCount');
    const filters = Array.from(document.querySelectorAll('[data-filter]'));
    let filter = 'all';

    const apply = () => {
        const term = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((row) => {
            const sent = row.dataset.sent === '1';
            const inFilter = filter === 'all' || (filter === 'sent' ? sent : !sent);
            const show = inFilter && (!term || (row.dataset.search || '').includes(term));
            row.hidden = !show;
            if (show) shown += 1;
        });
        if (none) {
            none.hidden = rows.length === 0 || shown > 0;
            none.querySelector('td').textContent = term ? 'No learner matches your search.' : 'No learner in this view.';
        }
        if (count) count.textContent = shown.toLocaleString() + (shown === 1 ? ' learner' : ' learners');
    };

    filters.forEach((button) => {
        button.addEventListener('click', () => {
            filter = button.dataset.filter;
            filters.forEach((other) => {
                other.classList.toggle('is-active', other === button);
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            apply();
        });
    });

    search.addEventListener('input', apply);
})();
</script>
</body>
</html>
