<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            <span class="badge">3</span>
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>
        <div class="sb-section-label">Health Programs</div>
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Program
        </a>
        <a href="{{ route('dashboard.school-nurse.deworming') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>
            Deworming Program
        </a>
        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Dispensing Log
        </a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Data Visualization
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Generate Reports
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(session('active_name', 'School Nurse'), 0, 2) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'School Nurse') }}</div>
            <div class="sb-user-role">School Nurse - DCNHS</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">></span>
            <span class="bc-current">Overview</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>DCNHS - SY 2025-2026</div>
    </header>

    <div class="content">
        <div class="page-header">
            <div>
                <div class="page-eyebrow">School Nurse Dashboard</div>
                <h1 class="page-title">Daily Clinic <span>Operations Overview</span></h1>
                <p class="page-sub">Track consultations, at-risk learners, and medicine inventory from one dashboard.</p>
            </div>
            <div class="page-header-actions">
                <a href="{{ route('dashboard.consultation-log') }}" class="btn btn-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Consultation Log
                </a>
                <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Open Health Records
                </a>
            </div>
        </div>

        <div class="mini-stats">
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:var(--g100);color:var(--g700)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">2,841</div>
                    <div class="mini-stat-label">Total Records</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#dbeafe;color:#1d4ed8">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">47</div>
                    <div class="mini-stat-label">Consultations Today</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fee2e2;color:var(--red)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">8</div>
                    <div class="mini-stat-label">At-Risk Learners</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fef3c7;color:#92400e">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">24</div>
                    <div class="mini-stat-label">Low Stock Medicines</div>
                </div>
            </div>
        </div>

        <div class="filter-bar">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" placeholder="Search learner, complaint, section...">
            </div>
            <select class="filter-select">
                <option>Today</option>
                <option>This Week</option>
                <option>This Month</option>
            </select>
            <select class="filter-select">
                <option>All Levels</option>
                <option>Junior High</option>
                <option>Senior High</option>
                <option>Personnel</option>
            </select>
        </div>

        <div class="table-card">
            <div class="table-head-bar">
                <span class="table-head-label">Recent Consultations</span>
                <span class="table-count">Showing 8 latest entries</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Grade / Dept</th>
                        <th>Date</th>
                        <th>Chief Complaint</th>
                        <th>Assessment</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <div class="td-person">
                                <div class="td-avatar">AS</div>
                                <div><div class="td-name">Andrei J. Santos</div><div class="td-id">100234560012</div></div>
                            </div>
                        </td>
                        <td>Grade 10<br><span style="font-size:.7rem;color:var(--text-3)">Rizal Sec 3</span></td>
                        <td>Apr 1, 2026</td>
                        <td>Headache, dizziness</td>
                        <td>Tension headache</td>
                        <td><span class="badge-pill bp-red"><span class="dot" style="background:var(--red)"></span>At-Risk</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div class="td-person">
                                <div class="td-avatar">ML</div>
                                <div><div class="td-name">Maria L. Dela Cruz</div><div class="td-id">100234560034</div></div>
                            </div>
                        </td>
                        <td>Grade 8<br><span style="font-size:.7rem;color:var(--text-3)">Bonifacio Sec 1</span></td>
                        <td>Apr 1, 2026</td>
                        <td>Fever (38.5 C)</td>
                        <td>Viral fever</td>
                        <td><span class="badge-pill bp-red"><span class="dot" style="background:var(--red)"></span>At-Risk</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div class="td-person">
                                <div class="td-avatar">CM</div>
                                <div><div class="td-name">Carlo R. Mendoza</div><div class="td-id">100234560078</div></div>
                            </div>
                        </td>
                        <td>Grade 9<br><span style="font-size:.7rem;color:var(--text-3)">Mabini Sec 2</span></td>
                        <td>Apr 1, 2026</td>
                        <td>Wound, right knee</td>
                        <td>Minor laceration</td>
                        <td><span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Normal</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div class="td-person">
                                <div class="td-avatar">LS</div>
                                <div><div class="td-name">Lorna G. Santos</div><div class="td-id">EMP-2019-041</div></div>
                            </div>
                        </td>
                        <td>Teaching<br><span style="font-size:.7rem;color:var(--text-3)">English Dept</span></td>
                        <td>Mar 30, 2026</td>
                        <td>Hypertension monitoring</td>
                        <td>Stage 1 hypertension</td>
                        <td><span class="badge-pill bp-amber"><span class="dot" style="background:var(--amber)"></span>Follow-up</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid-summary">
            <div class="panel">
                <div class="panel-title">Top Consultation Cases</div>
                <div class="trend-row">
                    <div class="trend-label">Headache</div>
                    <div class="trend-track"><div class="trend-fill" style="width:92%;background:var(--blue)"></div></div>
                    <div class="trend-value">92</div>
                </div>
                <div class="trend-row">
                    <div class="trend-label">Stomach Ache</div>
                    <div class="trend-track"><div class="trend-fill" style="width:78%;background:var(--blue)"></div></div>
                    <div class="trend-value">78</div>
                </div>
                <div class="trend-row">
                    <div class="trend-label">Fever / Colds</div>
                    <div class="trend-track"><div class="trend-fill" style="width:65%;background:var(--blue)"></div></div>
                    <div class="trend-value">65</div>
                </div>
                <div class="trend-row">
                    <div class="trend-label">Injury / Wounds</div>
                    <div class="trend-track"><div class="trend-fill" style="width:42%;background:var(--blue)"></div></div>
                    <div class="trend-value">42</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title">Medicine Stock Monitor</div>
                <div class="inventory-item">
                    <div class="inventory-meta"><span>Paracetamol 500mg</span><span>15% left</span></div>
                    <div class="trend-track"><div class="trend-fill" style="width:15%;background:var(--red)"></div></div>
                </div>
                <div class="inventory-item">
                    <div class="inventory-meta"><span>Amoxicillin</span><span>22% left</span></div>
                    <div class="trend-track"><div class="trend-fill" style="width:22%;background:var(--amber)"></div></div>
                </div>
                <div class="inventory-item">
                    <div class="inventory-meta"><span>Mefenamic Acid</span><span>30% left</span></div>
                    <div class="trend-track"><div class="trend-fill" style="width:30%;background:var(--amber)"></div></div>
                </div>
                <div class="inventory-item">
                    <div class="inventory-meta"><span>Vitamin C</span><span>67% left</span></div>
                    <div class="trend-track"><div class="trend-fill" style="width:67%;background:var(--g600)"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
