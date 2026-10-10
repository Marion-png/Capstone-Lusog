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

        <div class="kpi-grid">
            <div class="card kpi {{ $server['secure'] ? 'accent-brand' : ($server['configured'] ? 'accent-danger' : 'accent-amber') }}">
                <div class="kpi-top">
                    <div class="kpi-label">Receiving Server</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $server['configured'] ? $server['host'] : 'Not configured' }}</div>
                <div class="kpi-hint">
                    @if ($server['secure'])
                        HTTPS &middot; TLS 1.2+ &middot; {{ $server['auth'] === 'None' ? 'no credentials' : $server['auth'] }}
                    @elseif ($server['configured'])
                        Not HTTPS &mdash; sending refused
                    @else
                        FHIR_ENDPOINT is empty
                    @endif
                </div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Disclosure Mode</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $server['deidentified'] ? 'Pseudonymised' : 'Identified' }}</div>
                <div class="kpi-hint">{{ $server['deidentified'] ? 'No name, no LRN, birth year only' : 'Name, LRN and birth date' }}{{ $server['auto'] ? ' · auto-send on examination' : '' }}</div>
            </div>

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
            <div class="card-head">
                <div>
                    <div class="card-title">Learners</div>
                    <div class="card-sub">{{ number_format($learners->count()) }} on this school year's roll</div>
                </div>
            </div>

            <div class="toolbar">
                <div style="flex:1;min-width:240px">
                    <label class="field-label" for="fxSearch">Search</label>
                    <div class="lg-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="fxSearch" placeholder="Name, LRN, grade or section" autocomplete="off">
                    </div>
                </div>
                <div class="spacer"></div>
                <div class="toolbar-count" id="fxCount">{{ number_format($learners->count()) }} learners</div>
            </div>

            <div class="table-card">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Learner</th>
                                <th>LRN</th>
                                <th>Grade &amp; Section</th>
                                <th>Nutritional Status</th>
                                <th>Last Sent</th>
                            </tr>
                        </thead>
                        <tbody id="fxLearners">
                            @forelse ($learners as $learner)
                                @php $gradeSection = trim($learner['grade'].($learner['section'] !== '' ? ' — '.$learner['section'] : '')); @endphp
                                <tr class="js-fx-row" data-search="{{ mb_strtolower($learner['name'].' '.$learner['lrn'].' '.$gradeSection) }}">
                                    <td class="fx-name is-link">
                                        <a href="{{ route('dashboard.school-nurse.data-exchange.preview', $learner['lrn']) }}">{{ $learner['name'] !== '' ? $learner['name'] : $learner['lrn'] }}</a>
                                    </td>
                                    <td class="tnum">{{ $learner['lrn'] }}</td>
                                    <td>{{ $gradeSection !== '' ? $gradeSection : '—' }}</td>
                                    <td>{{ $learner['status'] !== '' ? $learner['status'] : 'Not measured' }}</td>
                                    <td class="tnum">{{ $learner['last_sent'] ? $learner['last_sent']->format('M j, Y g:i A') : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="table-empty">No learners on this school year's roll.</td></tr>
                            @endforelse
                            <tr id="fxNoMatch" hidden><td colspan="5" class="table-empty">No learner matches your search.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="fx-section">
            <div class="card-head">
                <div>
                    <div class="card-title">Transmission Log</div>
                    <div class="card-sub">Most recent first</div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Learner</th>
                                <th>Server</th>
                                <th class="num">Resources</th>
                                <th>Status</th>
                                <th class="num">HTTP</th>
                                <th>SHA-256</th>
                                <th>Sent By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($transmissions as $transmission)
                                @php
                                    $badge = match ($transmission->status) {
                                        'sent' => ['badge-normal', 'Sent'],
                                        'blocked' => ['badge-critical', 'Refused'],
                                        'pending' => ['badge-monitor', 'Pending'],
                                        default => ['badge-risk', 'Failed'],
                                    };
                                    $when = $transmission->sent_at ?? $transmission->created_at;
                                @endphp
                                <tr>
                                    <td class="fx-name is-link tnum">
                                        <a href="{{ route('dashboard.school-nurse.data-exchange.transmission', $transmission) }}">{{ $when?->format('M j, Y g:i A') }}</a>
                                    </td>
                                    <td>{{ $names[$transmission->student_lrn] ?? $transmission->student_lrn }}</td>
                                    <td>{{ $transmission->endpoint_host ?: '—' }}</td>
                                    <td class="num">{{ $transmission->resource_count }}</td>
                                    <td><span class="badge {{ $badge[0] }}">{{ $badge[1] }}</span></td>
                                    <td class="num">{{ $transmission->http_status ?: '—' }}</td>
                                    <td><span class="fx-hash" title="{{ $transmission->payload_sha256 }}">{{ substr((string) $transmission->payload_sha256, 0, 12) }}…</span></td>
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

    search.addEventListener('input', () => {
        const term = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((row) => {
            const show = !term || (row.dataset.search || '').includes(term);
            row.hidden = !show;
            if (show) shown += 1;
        });
        if (none) none.hidden = rows.length === 0 || shown > 0;
        if (count) count.textContent = shown.toLocaleString() + (shown === 1 ? ' learner' : ' learners');
    });
})();
</script>
</body>
</html>
