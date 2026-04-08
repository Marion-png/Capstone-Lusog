<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Deworming Request - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
@php
    $assignedClass = ($assignedGradeLevel && $assignedSection)
        ? ($assignedGradeLevel . ' / ' . $assignedSection)
        : 'Not Assigned';
@endphp

<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.class-adviser') }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="4"/>
                <rect x="14" y="12" width="7" height="9"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="sb-link-label">Dashboard</span>
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'form']) }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span class="sb-link-label">School Health Card Form</span>
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <span class="sb-link-label">My Students</span>
        </a>
        <a href="{{ route('dashboard.class-adviser.deworming') }}" class="sb-link active">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10.5 6.5l7 7a2.12 2.12 0 1 1-3 3l-7-7a2.12 2.12 0 0 1 3-3z"></path>
                <path d="M8.5 8.5l-3 3"></path>
            </svg>
            <span class="sb-link-label">Deworming Request</span>
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'CA',0,2) }}</div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Class Adviser' }}</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <path d="M16 17l5-5-5-5"/>
                    <path d="M21 12H9"/>
                </svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="top"><div class="crumb">Dashboard > Class Adviser > Deworming Request</div></header>
    <div class="content">
        <h1 class="title">Deworming <i>Request</i></h1>
        <p class="sub">Submit and track deworming tablet requests for your assigned class.</p>

        <div class="assigned-class-banner">
            <div>
                <div class="assigned-class-label">Assigned Class</div>
                <div class="assigned-class-value">{{ $assignedClass }}</div>
            </div>
            <div class="assigned-class-note">Requests are automatically tied to this class.</div>
        </div>

        @if (session('success'))
            <div class="flash flash-ok">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash flash-err">{{ $errors->first() }}</div>
        @endif

        <section class="card section" style="margin-top:12px;">
            <h3>Bi-Annual Deworming Campaign</h3>
            <p class="muted" style="font-size:.8rem;line-height:1.45;margin-bottom:10px;">Submit your tablet request based on signed parent consent forms. The School Nurse will review your request before release.</p>

            <form method="POST" action="{{ route('dashboard.class-adviser.deworming.store') }}" id="dewormingForm" autocomplete="off">
                @csrf
                <div class="student-grid" style="margin-bottom:10px;">
                    <div class="field">
                        <label for="campaign">Campaign</label>
                        <select id="campaign" name="campaign" required>
                            <option value="">Select Campaign</option>
                            <option value="start" {{ old('campaign') === 'start' ? 'selected' : '' }}>Start of School Year (June)</option>
                            <option value="end" {{ old('campaign') === 'end' ? 'selected' : '' }}>End of School Year (March)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Grade &amp; Section</label>
                        <input type="text" value="{{ $assignedClass }}" readonly>
                    </div>
                    <div class="field">
                        <label for="totalStudents">Total Students in Class</label>
                        <input type="number" id="totalStudents" name="total_students" min="1" value="{{ old('total_students') }}" required>
                    </div>
                    <div class="field">
                        <label for="consentingStudents">Number of Consenting Students</label>
                        <input type="number" id="consentingStudents" name="consenting_students" min="1" value="{{ old('consenting_students') }}" required>
                    </div>
                </div>

                <div class="calc-box" style="margin-bottom:10px;">
                    <div style="font-size:.78rem;color:#48685a;font-weight:700;">Tablets Requested</div>
                    <div class="calc-grid" style="grid-template-columns:1fr;max-width:220px;">
                        <div class="calc-item"><div class="label">Requested Tablets</div><div class="value" id="tabletsRequested">{{ old('consenting_students', 0) }}</div></div>
                    </div>
                    <div style="font-size:.72rem;color:#6f8c7a;margin-top:6px;">One tablet per consenting student.</div>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-secondary" id="resetDewormingForm">Reset</button>
                    <button type="submit" class="btn">Submit Request</button>
                </div>
            </form>
        </section>

        <section class="card section" style="margin-top:12px;">
            <h3>Request History</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date Submitted</th>
                        <th>Campaign</th>
                        <th>Students</th>
                        <th>Consenting</th>
                        <th>Tablets</th>
                        <th>Status</th>
                        <th>Release Date</th>
                        <th>Nurse Comment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dewormingRequests as $item)
                        @php
                            $status = (string) ($item['status'] ?? 'pending');
                            $statusClass = $status === 'approved' ? 'over' : ($status === 'prepared' ? 'risk' : ($status === 'released' ? 'ok' : 'warn'));
                            $statusLabel = ucfirst($status);
                        @endphp
                        <tr>
                            <td>{{ isset($item['submitted_at']) ? \Illuminate\Support\Carbon::parse($item['submitted_at'])->format('Y-m-d') : '-' }}</td>
                            <td>{{ ($item['campaign'] ?? '') === 'start' ? 'Start of SY' : 'End of SY' }}</td>
                            <td>{{ $item['total_students'] ?? '-' }}</td>
                            <td>{{ $item['consenting_students'] ?? '-' }}</td>
                            <td>{{ $item['tablets_requested'] ?? '-' }}</td>
                            <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                            <td>{{ $item['released_date'] ?? '-' }}</td>
                            <td>{{ $item['nurse_comment'] ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No requests submitted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>

<script>
(() => {
    const totalStudentsInput = document.getElementById('totalStudents');
    const consentingStudentsInput = document.getElementById('consentingStudents');
    const tabletsDisplay = document.getElementById('tabletsRequested');
    const resetBtn = document.getElementById('resetDewormingForm');
    const form = document.getElementById('dewormingForm');

    if (!totalStudentsInput || !consentingStudentsInput || !tabletsDisplay || !resetBtn || !form) {
        return;
    }

    const updateTablets = () => {
        const consenting = parseInt(consentingStudentsInput.value || '0', 10);
        tabletsDisplay.textContent = Number.isFinite(consenting) && consenting > 0 ? String(consenting) : '0';
    };

    totalStudentsInput.addEventListener('input', updateTablets);
    consentingStudentsInput.addEventListener('input', updateTablets);

    resetBtn.addEventListener('click', () => {
        form.reset();
        tabletsDisplay.textContent = '0';
    });

    updateTablets();
})();
</script>
</body>
</html>
