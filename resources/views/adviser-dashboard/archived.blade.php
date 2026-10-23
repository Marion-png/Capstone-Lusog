<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Archived - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{!! file_get_contents(resource_path('css/class-adviser.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/adviser-transition.css')) !!}</style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'archived'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Archived'])

    <div class="content">
        <div class="ms-page-header">
            <div>
                <h2 class="ms-page-title">Archived</h2>
                <p class="ms-page-sub">{{ \App\Support\LearnerPlacement::sectionLabel($grade, $section) }}</p>
            </div>
            <div class="ms-header-actions">
                <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="btn btn-secondary">My Students</a>
            </div>
        </div>

        @if (! $supported)
            <div class="flash flash-err">The database has not been updated for the school-year handover yet.</div>
        @endif

        {{-- Only the learners who left this adviser's own section. The record
             opens read-only; Restore puts the learner back in the section
             and cancels the deletion. --}}
        <article class="ms-table-container">
            <div class="ms-table-header">
                <h3>
                    Archived Learners
                    <span class="ms-count-badge">{{ $rows->count() }}</span>
                </h3>
            </div>
            <div class="ms-table-scroll">
                <table class="ms-table">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Section</th>
                            <th>School Year</th>
                            <th>Reason</th>
                            <th>Archived</th>
                            <th>Deletion Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td><strong>{{ $row['lrn'] }}</strong></td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['section'] }}</td>
                                <td>{{ $row['school_year'] }}</td>
                                <td>{{ $row['reason'] !== '' ? $row['reason'] : '—' }}</td>
                                <td>{{ $row['archived_at']?->format('j M Y') ?? '—' }}</td>
                                <td><strong>{{ $row['delete_after']?->format('j M Y') ?? '—' }}</strong></td>
                                <td>
                                    <div class="yt-inline">
                                        <a href="{{ $row['view_url'] }}" class="btn btn-secondary yt-btn-sm" target="_blank" rel="noopener">View Record</a>
                                        <form method="POST" action="{{ route('class-adviser.archived.restore', $row['lrn']) }}" class="yt-inline js-restore"
                                              data-confirm="Restore {{ $row['name'] }} to your section?">
                                            @csrf
                                            <button type="submit" class="btn yt-btn-sm">Restore</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="ms-empty-state">
                                        <h4>No Archived Learners</h4>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</div>

<script>
(() => {
    document.querySelectorAll('.js-restore').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
})();
</script>
</body>
</html>
