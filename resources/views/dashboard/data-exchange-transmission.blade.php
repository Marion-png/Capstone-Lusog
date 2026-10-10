<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    @php
        $learnerName = $record ? (trim((string) $record->student_name) ?: (string) $record->student_id) : (string) $transmission->student_lrn;
        $statusBadge = match ($transmission->status) {
            'sent' => ['badge-normal', 'Sent', 'accent-success'],
            'blocked' => ['badge-critical', 'Refused', 'accent-danger'],
            'pending' => ['badge-monitor', 'Pending', 'accent-amber'],
            default => ['badge-risk', 'Failed', 'accent-orange'],
        };
        $when = $transmission->sent_at ?? $transmission->created_at;
        $trigger = match ($transmission->trigger) {
            'examination' => 'Automatic, after an examination',
            'console' => 'Console command',
            default => 'Sent from this screen',
        };
    @endphp
    <title>Transmission #{{ $transmission->id }} - SIGLA</title>
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
            <span>Transmission #{{ $transmission->id }}</span>
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
                    <div class="page-eyebrow">FHIR R4 &middot; Transmission</div>
                    <h1 class="page-title">{{ $learnerName }} <span>#{{ $transmission->id }}</span></h1>
                    <p class="page-sub">{{ $when?->format('F j, Y · g:i:s A') }} &middot; {{ $transmission->endpoint_url ?: $transmission->endpoint_host }}</p>
                </div>
                @if ($record)
                    <div class="fx-actions">
                        <a href="{{ route('dashboard.school-nurse.data-exchange.preview', $record->student_id) }}" class="btn btn-secondary">Current Bundle</a>
                    </div>
                @endif
            </div>
        </div>

        <div class="kpi-grid">
            <div class="card kpi {{ $statusBadge[2] }}">
                <div class="kpi-top">
                    <div class="kpi-label">Status</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $statusBadge[1] }}</div>
                <div class="kpi-hint">{{ $transmission->http_status ? 'HTTP '.$transmission->http_status : 'No HTTP response' }}</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Receiving Server</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $transmission->endpoint_host ?: '—' }}</div>
                <div class="kpi-hint">{{ str_starts_with((string) $transmission->endpoint_url, 'https://') ? 'HTTPS · TLS 1.2+' : 'Not HTTPS' }}</div>
            </div>

            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Resources</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ $transmission->resource_count }}</div>
                <div class="kpi-hint">{{ $transmission->deidentified ? 'Pseudonymised' : 'Identified' }}</div>
            </div>

            <div class="card kpi {{ $intact ? 'accent-success' : 'accent-danger' }}">
                <div class="kpi-top">
                    <div class="kpi-label">Payload Integrity</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $intact ? 'Verified' : 'Mismatch' }}</div>
                <div class="kpi-hint">Stored copy against SHA-256</div>
            </div>
        </div>

        <div class="fx-grid">
            <div class="table-card">
                <div class="fx-code-head">
                    <div>
                        <div class="fx-code-title">Server Response</div>
                        <div class="fx-code-meta">{{ $transmission->http_status ? 'HTTP '.$transmission->http_status : 'No response received' }}</div>
                    </div>
                    @if ($responseJson !== '')
                        <button type="button" class="fx-copy" data-copy-target="fxResponse">Copy JSON</button>
                    @endif
                </div>

                @if ($transmission->error_message)
                    <div class="fx-inset"><p class="fx-error">{{ $transmission->error_message }}</p></div>
                @endif

                @if ($outcomes !== [])
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th class="num">#</th>
                                    <th>Outcome</th>
                                    <th>Location on the receiving server</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($outcomes as $i => $outcome)
                                    <tr>
                                        <td class="num">{{ $i + 1 }}</td>
                                        <td class="tnum">{{ $outcome['status'] ?: '—' }}</td>
                                        <td><span class="fx-hash">{{ $outcome['location'] ?: '—' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($responseJson !== '')
                    <pre class="fx-json is-short" id="fxResponse">{{ $responseJson }}</pre>
                @elseif (! $transmission->error_message)
                    <p class="fx-empty fx-inset">No response body.</p>
                @endif
            </div>

            <div class="fx-side">
                <div class="card fx-panel">
                    <div class="card-head"><div class="card-title">Record</div></div>
                    <dl class="fx-facts">
                        <div><dt>Learner</dt><dd>{{ $learnerName }}</dd></div>
                        <div><dt>LRN</dt><dd class="tnum">{{ $transmission->student_lrn }}</dd></div>
                        <div><dt>Requested by</dt><dd>{{ $transmission->sent_by_name ?: '—' }}</dd></div>
                        <div><dt>Trigger</dt><dd>{{ $trigger }}</dd></div>
                        <div><dt>Request ID</dt><dd><span class="fx-hash">{{ $transmission->uuid }}</span></dd></div>
                        <div><dt>SHA-256</dt><dd><span class="fx-hash">{{ $transmission->payload_sha256 }}</span></dd></div>
                    </dl>
                </div>
            </div>
        </div>

        <section class="fx-section">
            <div class="table-card">
                <div class="fx-code-head">
                    <div>
                        <div class="fx-code-title">{{ $transmission->status === 'blocked' ? 'Payload Withheld' : 'Payload Sent' }}</div>
                        <div class="fx-code-meta">{{ number_format(strlen((string) $transmission->payload) / 1024, 1) }} KB &middot; application/fhir+json</div>
                    </div>
                    <button type="button" class="fx-copy" data-copy-target="fxPayload">Copy JSON</button>
                </div>
                <pre class="fx-json" id="fxPayload">{{ $transmission->payload }}</pre>
            </div>
        </section>
    </div>
</div>

@include('partials.nurse-page-transition')
@include('partials.fhir-json-script')
</body>
</html>
