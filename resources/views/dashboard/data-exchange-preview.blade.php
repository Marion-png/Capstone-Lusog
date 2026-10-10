<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    @php
        $details = is_array($record->student_details) ? $record->student_details : [];
        $learnerName = trim((string) $record->student_name) ?: (string) $record->student_id;
        $grade = trim((string) ($details['grade_level'] ?? ''));
        $section = trim((string) ($details['section'] ?? ''));
        $gradeSection = trim($grade.($section !== '' ? ' — '.$section : ''));
    @endphp
    <title>FHIR Bundle - {{ $learnerName }} - SIGLA</title>
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
        <div class="topbar-bc">
            <span>School Nurse</span><span class="bc-sep">&rsaquo;</span>
            <a href="{{ route('dashboard.school-nurse.data-exchange') }}">Health Data Exchange</a><span class="bc-sep">&rsaquo;</span>
            <span>{{ $learnerName }}</span>
        </div>
        <div class="topbar-spacer"></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        <a href="{{ route('dashboard.school-nurse.data-exchange') }}" class="fx-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            Health Data Exchange
        </a>

        <div class="page-header">
            <div class="card-head" style="margin-bottom:0">
                <div>
                    <div class="page-eyebrow">FHIR R4 &middot; Transaction Bundle</div>
                    <h1 class="page-title">{{ $learnerName }} <span>Bundle</span></h1>
                    <p class="page-sub">LRN {{ $record->student_id }}{{ $gradeSection !== '' ? ' · '.$gradeSection : '' }} &middot; S.Y. {{ $record->school_year }}</p>
                </div>
                <div class="fx-actions">
                    <a href="{{ route('dashboard.school-nurse.data-exchange.download', $record->student_id) }}" class="btn btn-secondary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download JSON
                    </a>
                    <form method="POST" action="{{ route('dashboard.school-nurse.data-exchange.transmit', $record->student_id) }}"
                          data-confirm="Send {{ $learnerName }}'s record to {{ $server['host'] ?: 'the receiving server' }}?">
                        @csrf
                        <button type="submit" class="btn btn-primary" @disabled(! $server['configured'])>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Transmit
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Resources</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ array_sum($summary) }}</div>
                <div class="kpi-hint">{{ count($summary) }} resource types</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Payload</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($bytes / 1024, 1) }} <small>KB</small></div>
                <div class="kpi-hint">application/fhir+json</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Disclosure Mode</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $deidentified ? 'Pseudonymised' : 'Identified' }}</div>
                <div class="kpi-hint">{{ $deidentified ? 'No name, no LRN, birth year only' : 'Name, LRN and birth date' }}</div>
            </div>

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
                        HTTPS &middot; TLS 1.2+
                    @elseif ($server['configured'])
                        Not HTTPS &mdash; sending refused
                    @else
                        FHIR_ENDPOINT is empty
                    @endif
                </div>
            </div>
        </div>

        <div class="fx-grid">
            <div class="table-card">
                <div class="fx-code-head">
                    <div>
                        <div class="fx-code-title">Bundle</div>
                        <div class="fx-code-meta">Generated {{ now()->format('M j, Y g:i A') }}</div>
                    </div>
                    <button type="button" class="fx-copy" data-copy-target="fxBundle">Copy JSON</button>
                </div>
                <pre class="fx-json" id="fxBundle">{{ $json }}</pre>
            </div>

            <div class="fx-side">
                <div class="card fx-panel">
                    <div class="card-head"><div class="card-title">Contents</div></div>
                    <ul class="fx-counts">
                        @foreach ($summary as $type => $count)
                            <li><span>{{ $type }}</span><span class="tnum">{{ $count }}</span></li>
                        @endforeach
                    </ul>
                </div>

                <div class="card fx-panel">
                    <div class="card-head"><div class="card-title">SHA-256</div></div>
                    <span class="fx-hash">{{ $sha256 }}</span>
                </div>

                <div class="card fx-panel">
                    <div class="card-head"><div class="card-title">Transmissions</div></div>
                    @if ($history->isEmpty())
                        <p class="fx-empty">None for this learner yet.</p>
                    @else
                        <ul class="fx-history">
                            @foreach ($history as $item)
                                @php
                                    $badge = match ($item->status) {
                                        'sent' => ['badge-normal', 'Sent'],
                                        'blocked' => ['badge-critical', 'Refused'],
                                        'pending' => ['badge-monitor', 'Pending'],
                                        default => ['badge-risk', 'Failed'],
                                    };
                                @endphp
                                <li>
                                    <a href="{{ route('dashboard.school-nurse.data-exchange.transmission', $item) }}">
                                        <span class="tnum">{{ ($item->sent_at ?? $item->created_at)?->format('M j, Y g:i A') }}</span>
                                        <span class="badge {{ $badge[0] }}">{{ $badge[1] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')
@include('partials.fhir-json-script')

<script>
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
</script>
</body>
</html>
