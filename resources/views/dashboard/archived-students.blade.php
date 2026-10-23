<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    @php $arRoleLabel = \App\Support\AccountSettings::roleLabel((string) session('active_role', '')); @endphp
    <title>Archived Students - {{ $arRoleLabel }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.clinic-rail', ['active' => 'archived'])

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>{{ $arRoleLabel }}</span><span class="bc-sep">&rsaquo;</span><span>Archived Students</span></div>
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ session('active_school_name', 'No school assigned') }} &middot; SY {{ \App\Models\StudentHealthRecord::currentSchoolYear() }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">Archived <span>Students</span></h1>
        </div>

        @if (! $supported)
            <div class="flash err">The database has not been updated for the school-year handover yet.</div>
        @endif

        {{-- Read only. Every learner the school holds who has left — kept
             until the date beside them, then deleted with every file — and
             every learner waiting to be placed in a section. Restoring or
             placing a learner is a class adviser's. --}}
        <h2 class="section-title">Archived <span class="badge badge-neutral">{{ $archived->count() }}</span></h2>
        <div class="table-card" style="margin-bottom:22px">
            <div class="table-scroll">
                <table id="archivedTable">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Last Section</th>
                            <th>School Year</th>
                            <th>Reason</th>
                            <th>Archived</th>
                            <th>Deletion Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($archived as $row)
                            <tr>
                                <td class="tnum"><strong>{{ $row['lrn'] }}</strong></td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['section'] }}</td>
                                <td class="tnum">{{ $row['school_year'] }}</td>
                                <td>{{ $row['reason'] !== '' ? $row['reason'] : '—' }}</td>
                                <td class="tnum">{{ $row['archived_at']?->format('j M Y') ?? '—' }}</td>
                                <td class="tnum"><span class="badge badge-monitor">{{ $row['delete_after']?->format('j M Y') ?? '—' }}</span></td>
                                <td><a href="{{ $row['view_url'] }}" class="btn btn-secondary" target="_blank" rel="noopener">View Record</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="table-empty">No archived learners.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <h2 class="section-title">Waiting for a Section <span class="badge badge-neutral">{{ $waiting->count() }}</span></h2>
        <div class="table-card">
            <div class="table-scroll">
                <table id="waitingTable">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Last Section</th>
                            <th>School Year</th>
                            <th>Status</th>
                            <th>Waiting For</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($waiting as $row)
                            <tr>
                                <td class="tnum"><strong>{{ $row['lrn'] }}</strong></td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['section'] }}</td>
                                <td class="tnum">{{ $row['school_year'] }}</td>
                                <td>{{ $row['reason'] !== '' ? $row['reason'] : '—' }}</td>
                                <td><span class="badge badge-info">{{ $row['wait_grade'] !== '' ? $row['wait_grade'] : '—' }}</span></td>
                                <td><a href="{{ $row['view_url'] }}" class="btn btn-secondary" target="_blank" rel="noopener">View Record</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="table-empty">No learners are waiting for a section.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')
</body>
</html>
