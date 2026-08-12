<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Clinic Staff Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the clinic rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/clinic-staff.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.clinic-rail', ['active' => 'dashboard'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
        $stats = $stats ?? [];
        $consultations = $consultations ?? collect();
        $medicines = $medicines ?? collect();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Clinic Staff</span></div>
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <div class="page-eyebrow">Operations Workspace</div>
            <h1 class="page-title">Clinic Staff <span>Operations Hub</span></h1>
            <p class="page-sub">Daily encoding, triage updates and follow-up tracking for {{ $schoolName }}.</p>
        </div>

        <div class="kpi-grid">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Walk-ins Today</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['walk_ins_today'] ?? 0) }}</div>
                <div class="kpi-hint">Consultations logged today</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Records Encoded</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['encoded_total'] ?? 0) }}</div>
                <div class="kpi-hint">Most recent consultations on file</div>
            </div>

            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Medicines Dispensed</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['dispensed_today'] ?? 0) }}</div>
                <div class="kpi-hint">Units issued today by the nurse</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Pending Endorsements</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['pending_endorsements'] ?? 0) }}</div>
                <div class="kpi-hint">Cards awaiting nurse examination</div>
            </div>
        </div>

        <div style="margin-top:20px">
            @include('partials.announcements')
        </div>

        <div class="clinic-grid">
            <div class="card clinic-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">Recent Consultations</div>
                        <div class="card-sub">Latest clinic visits for this school</div>
                    </div>
                    <a href="{{ route('dashboard.consultation-log') }}" class="btn btn-secondary">Open log</a>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Name</th>
                                <th>Complaint</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($consultations->take(6) as $consultation)
                                <tr>
                                    <td class="tnum">{{ $consultation->consulted_at?->format('H:i') ?? '—' }}</td>
                                    <td><strong>{{ $consultation->student_name ?: '—' }}</strong></td>
                                    <td>{{ $consultation->condition ?: '—' }}</td>
                                    <td>
                                        @if ($consultation->status === 'referred')
                                            <span class="badge badge-monitor">Referred</span>
                                        @else
                                            <span class="badge badge-normal">Encoded</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="table-empty">No consultations recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card clinic-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">Medicine Stock</div>
                        <div class="card-sub">Against each item's reorder point</div>
                    </div>
                    <a href="{{ route('dashboard.medicine-inventory') }}" class="btn btn-secondary">Inventory</a>
                </div>

                @forelse ($medicines as $medicine)
                    @php
                        $threshold = max(1, (int) $medicine->minimum_threshold);
                        $pct = min(100, (int) round($medicine->stock_quantity / $threshold * 100));
                        $state = $medicine->stock_quantity === 0
                            ? 'is-out'
                            : ($medicine->stock_quantity < $threshold ? 'is-low' : 'is-ok');
                    @endphp
                    <div class="stock-row {{ $state }}">
                        <div class="stock-meta">
                            <strong>{{ $medicine->name }}</strong>
                            <span class="tnum">{{ $medicine->stock_quantity }} {{ $medicine->unit }} left</span>
                        </div>
                        <div class="meter-track"><div class="meter-fill" style="width:{{ $pct }}%"></div></div>
                    </div>
                @empty
                    <div class="empty-panel">No medicines on the shelf list yet.</div>
                @endforelse
            </div>
        </div>

        <div class="card clinic-panel" style="margin-top:20px">
            <div class="card-head">
                <div>
                    <div class="card-title">Feeding Program At-Risk Alerts</div>
                    <div class="card-sub">Derived from attendance only — never from nutritional status</div>
                </div>
            </div>

            @if (($atRiskStudents ?? collect())->isNotEmpty())
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Learner</th>
                                <th>Section</th>
                                <th class="num">Attendance</th>
                                <th>Nutritional Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($atRiskStudents as $student)
                                <tr>
                                    <td><strong>{{ $student->student_name }}</strong></td>
                                    <td>{{ $student->section }}</td>
                                    <td class="num">{{ $student->attendance_sessions_count }}/120 days</td>
                                    <td><span class="badge badge-risk">{{ $student->nutritional_status }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty-panel">No at-risk beneficiaries flagged at this time.</div>
            @endif
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')
</body>
</html>
