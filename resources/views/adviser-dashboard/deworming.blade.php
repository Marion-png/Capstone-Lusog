<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Deworming Request - SIGLA</title>
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

@include('partials.adviser-sidebar', ['active' => 'deworming'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Deworming Request'])
    <div class="content">
        <h1 class="title">Deworming <i>Request</i></h1>
        <p class="sub">Submit and track deworming tablet requests for your assigned class.</p>

        @if ($errors->any())
            <div class="flash flash-err">{{ $errors->first() }}</div>
        @endif

        <section class="card section" style="margin-top:12px;">
            <h3>Bi-Annual Deworming Campaign</h3>
            <p class="muted" style="font-size:.8rem;line-height:1.45;margin-bottom:10px;">Submit your tablet request based on signed parent consent forms. The Clinical Teacher will review your request before release.</p>

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
                        <th>Clinical Teacher Comment</th>
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
