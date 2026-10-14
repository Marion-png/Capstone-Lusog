<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    @php
        $learnerName = $record ? (trim((string) $record->student_name) ?: (string) $record->student_id) : (string) $transmission->student_lrn;
        $tone = in_array($transmission->status, ['sent', 'blocked', 'pending'], true) ? $transmission->status : 'failed';
        $resultTitle = match ($tone) {
            'sent' => 'Transmitted',
            'blocked' => 'Refused',
            'pending' => 'Pending',
            default => 'Not delivered',
        };
        $when = $transmission->sent_at ?? $transmission->created_at;
        $trigger = match ($transmission->trigger) {
            'examination' => 'Automatic, after an examination',
            'console' => 'Console command',
            default => 'Sent from this screen',
        };
        $updated = count($outcomes) - $created;
        $defaultPane = $responseJson !== '' ? 'response' : 'payload';
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

        <div class="page-header fx-page-header">
            <div>
                <div class="page-eyebrow">FHIR R4 &middot; Transmission #{{ $transmission->id }}</div>
                <h1 class="page-title">{{ $learnerName }} <span>Transmission</span></h1>
                <p class="page-sub">{{ $when?->format('F j, Y · g:i:s A') }} &middot; {{ $transmission->endpoint_url ?: $transmission->endpoint_host }}</p>
            </div>
            @if ($record)
                <div class="fx-actions">
                    <a href="{{ route('dashboard.school-nurse.data-exchange.preview', $record->student_id) }}" class="btn btn-secondary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        Current Bundle
                    </a>
                </div>
            @endif
        </div>

        <section class="fx-result is-{{ $tone }}" aria-labelledby="fxResultTitle">
            <div class="fx-result-head">
                <div class="fx-result-icon" aria-hidden="true">
                    @switch ($tone)
                        @case ('sent')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
                            @break
                        @case ('blocked')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            @break
                        @case ('pending')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                            @break
                        @default
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    @endswitch
                </div>
                <div class="fx-result-text">
                    <h2 class="fx-result-title" id="fxResultTitle">{{ $resultTitle }}</h2>
                    <div class="fx-result-meta">
                        {{ $transmission->http_status ? 'HTTP '.$transmission->http_status : 'No HTTP response' }}
                        &middot; {{ $transmission->resource_count }} {{ $transmission->resource_count === 1 ? 'resource' : 'resources' }}
                        &middot; {{ $transmission->endpoint_host ?: '—' }}
                    </div>
                </div>
                @include('partials.fhir-status-badge', ['status' => $transmission->status])
            </div>

            @if ($patient)
                <div class="fx-result-body">
                    <div class="fx-pid">
                        <div class="fx-pid-label">Patient ID</div>
                        <div class="fx-pid-id" id="fxPatientId">{{ $patient['id'] }}</div>
                        <div class="fx-pid-foot">
                            <button type="button" class="fx-copy" data-copy-text="{{ $patient['id'] }}">Copy ID</button>
                            @if ($patient['version'] !== '')
                                <span class="fx-pid-version">Version {{ $patient['version'] }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="fx-result-url">
                        <div class="fx-pid-label">Resource URL</div>
                        @include('partials.fhir-read-url', ['outcome' => $patient])
                    </div>
                </div>
            @elseif ($transmission->error_message)
                <p class="fx-error fx-result-error">{{ $transmission->error_message }}</p>
            @endif
        </section>

        <div class="kpi-grid">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Resources</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ $transmission->resource_count }}</div>
                <div class="kpi-hint">{{ $outcomes !== [] ? $created.' created · '.$updated.' updated' : ($transmission->http_status ? 'None accepted' : 'No server reply') }}</div>
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

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Disclosure Mode</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                </div>
                <div class="kpi-value fx-kpi-text">{{ $transmission->deidentified ? 'Pseudonymised' : 'Identified' }}</div>
                <div class="kpi-hint">{{ $transmission->deidentified ? 'No name, no LRN, birth year only' : 'Name, LRN and birth date' }}</div>
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

        @if ($outcomes !== [])
            <section class="fx-section">
                <div class="table-card">
                    <div class="fx-card-head">
                        <div>
                            <div class="card-title">Resources on the Server</div>
                            <div class="card-sub">{{ count($outcomes) }} {{ count($outcomes) === 1 ? 'entry' : 'entries' }} &middot; {{ $transmission->endpoint_host }}</div>
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="fx-outcomes">
                            <thead>
                                <tr>
                                    <th class="num">#</th>
                                    <th>Resource</th>
                                    <th>Outcome</th>
                                    <th>Resource URL</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($outcomes as $i => $outcome)
                                    @php
                                        $outcomeBadge = match (true) {
                                            $outcome['created'] => 'badge-normal',
                                            str_starts_with($outcome['status'], '2') => 'badge-info',
                                            $outcome['status'] === '' => 'badge-neutral',
                                            default => 'badge-risk',
                                        };
                                    @endphp
                                    <tr class="{{ $outcome['resource_type'] === 'Patient' ? 'is-patient' : '' }}">
                                        <td class="num">{{ $i + 1 }}</td>
                                        <td class="fx-resource">
                                            <strong>{{ $outcome['resource_type'] ?: '—' }}</strong>
                                            @if ($outcome['label'] !== '')
                                                <span>{{ $outcome['label'] }}</span>
                                            @endif
                                        </td>
                                        <td><span class="badge {{ $outcomeBadge }}">{{ $outcome['status'] ?: 'No status' }}</span></td>
                                        <td>
                                            @if ($outcome['url'] !== '')
                                                @include('partials.fhir-read-url', ['outcome' => $outcome, 'compact' => true])
                                            @else
                                                <span class="fx-hash">{{ $outcome['location'] ?: '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif

        <div class="fx-grid">
            <div class="table-card">
                <div class="fx-code-head">
                    <div class="fx-seg" role="tablist" aria-label="JSON">
                        <button type="button" role="tab" data-pane="fxResponse" class="{{ $defaultPane === 'response' ? 'is-active' : '' }}" aria-selected="{{ $defaultPane === 'response' ? 'true' : 'false' }}">Server Response</button>
                        <button type="button" role="tab" data-pane="fxPayload" class="{{ $defaultPane === 'payload' ? 'is-active' : '' }}" aria-selected="{{ $defaultPane === 'payload' ? 'true' : 'false' }}">{{ $transmission->status === 'blocked' ? 'Payload Withheld' : 'Payload Sent' }}</button>
                    </div>
                    <button type="button" class="fx-copy" data-copy-target="{{ $defaultPane === 'response' ? 'fxResponse' : 'fxPayload' }}" data-copy-pane>Copy JSON</button>
                </div>

                <div class="fx-pane{{ $defaultPane === 'response' ? ' is-active' : '' }}" data-pane-id="fxResponse">
                    <div class="fx-pane-meta">{{ $transmission->http_status ? 'HTTP '.$transmission->http_status : 'No response received' }}</div>
                    @if ($responseJson !== '')
                        <pre class="fx-json" id="fxResponse">{{ $responseJson }}</pre>
                    @else
                        <p class="fx-empty fx-inset" id="fxResponse">No response body.</p>
                    @endif
                </div>

                <div class="fx-pane{{ $defaultPane === 'payload' ? ' is-active' : '' }}" data-pane-id="fxPayload">
                    <div class="fx-pane-meta">{{ number_format(strlen((string) $transmission->payload) / 1024, 1) }} KB &middot; application/fhir+json</div>
                    <pre class="fx-json" id="fxPayload">{{ $transmission->payload }}</pre>
                </div>
            </div>

            <div class="fx-side">
                <div class="card fx-panel">
                    <div class="card-head"><div class="card-title">Record</div></div>
                    <dl class="fx-facts">
                        <div><dt>Learner</dt><dd>{{ $learnerName }}</dd></div>
                        <div><dt>LRN</dt><dd class="tnum">{{ $transmission->student_lrn }}</dd></div>
                        @if ($patient)
                            <div><dt>Patient ID</dt><dd><span class="fx-id">{{ $patient['id'] }}</span></dd></div>
                        @endif
                        <div><dt>Requested by</dt><dd>{{ $transmission->sent_by_name ?: '—' }}</dd></div>
                        <div><dt>Trigger</dt><dd>{{ $trigger }}</dd></div>
                        <div><dt>Request ID</dt><dd><span class="fx-hash">{{ $transmission->uuid }}</span></dd></div>
                        <div><dt>SHA-256</dt><dd><span class="fx-hash">{{ $transmission->payload_sha256 }}</span></dd></div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')
@include('partials.fhir-json-script')
</body>
</html>
