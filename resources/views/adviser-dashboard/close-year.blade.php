<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Close School Year - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{!! file_get_contents(resource_path('css/class-adviser.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/adviser-transition.css')) !!}</style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'students'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Close School Year'])

    <div class="content">
        @php $sectionLabel = \App\Support\LearnerPlacement::sectionLabel($grade, $section); @endphp

        @if ($errors->any())
            <div class="flash flash-err">{{ $errors->first() }}</div>
        @endif

        <div class="ms-page-header">
            <div>
                <h2 class="ms-page-title">Close School Year</h2>
                <p class="ms-page-sub">{{ $sectionLabel }} &middot; S.Y. {{ $schoolYear }}</p>
                @if ($closure)
                    <div class="yt-meta">
                        <span class="yt-chip is-closed" id="sectionClosedChip">Closed {{ $closure->closed_at?->format('j M Y') }}</span>
                    </div>
                @endif
            </div>
            <div class="ms-header-actions">
                <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="btn btn-secondary">My Students</a>
                <a href="{{ route('dashboard.class-adviser.archived') }}" class="btn btn-secondary">Archived</a>
            </div>
        </div>

        @if (! $supported)
            <div class="flash flash-err">The database has not been updated for the school-year handover yet.</div>
        @elseif ($open->isNotEmpty())
            {{-- One status per learner. What each status will do to the record
                 is shown beside it before anything is saved, and the whole
                 section is written in one transaction on Submit. --}}
            <form method="POST" action="{{ route('class-adviser.close-year.store') }}" id="closeYearForm"
                  data-confirm="Close S.Y. {{ $schoolYear }} for {{ $sectionLabel }}? The section becomes read-only.">
                @csrf
                <input type="hidden" name="school_year" value="{{ $schoolYear }}">

                <article class="ms-table-container">
                    <div class="ms-table-header">
                        <h3>
                            Learners
                            <span class="ms-count-badge">{{ $open->count() }}</span>
                        </h3>
                    </div>
                    <div class="ms-table-scroll">
                        <table class="ms-table">
                            <thead>
                                <tr>
                                    <th>LRN</th>
                                    <th>Student Name</th>
                                    <th>End-of-Year Status</th>
                                    <th>What Happens</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($open as $row)
                                    <tr class="js-close-row">
                                        <td><strong>{{ $row['lrn'] }}</strong></td>
                                        <td>{{ $row['name'] }}</td>
                                        <td>
                                            <select name="statuses[{{ $row['lrn'] }}]" class="yt-select js-end-status" aria-label="End-of-year status for {{ $row['name'] }}">
                                                @foreach ($row['options'] as $status => $option)
                                                    <option value="{{ $status }}" data-outcome="{{ $option['outcome'] }}" @selected($status === $row['default'])>{{ $option['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="yt-outcome js-end-outcome">{{ $row['options'][$row['default']]['outcome'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="yt-actions">
                        <button type="submit" class="btn" id="closeYearSubmit">Submit</button>
                    </div>
                </article>
            </form>
        @elseif ($closure)
            {{-- The closed section, read only. Who was in it and the status
                 they were given — never where a learner is now. A status can
                 be corrected until the learner is placed in a new section. --}}
            <article class="ms-table-container">
                <div class="ms-table-header">
                    <h3>
                        Learners
                        <span class="ms-count-badge">{{ $closed->count() }}</span>
                    </h3>
                </div>
                <div class="ms-table-scroll">
                    <table class="ms-table">
                        <thead>
                            <tr>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>End-of-Year Status</th>
                                <th>Record</th>
                                <th>Correct Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($closed as $row)
                                <tr>
                                    <td><strong>{{ $row['lrn'] }}</strong></td>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['status_label'] }}</td>
                                    <td class="yt-outcome">{{ $row['standing'] }}</td>
                                    <td>
                                        @if ($row['can_correct'])
                                            <form method="POST" action="{{ route('class-adviser.close-year.correct', $row['id']) }}" class="yt-inline">
                                                @csrf
                                                <select name="status" class="yt-select" aria-label="Correct the status for {{ $row['name'] }}">
                                                    @foreach ($row['options'] as $status => $label)
                                                        <option value="{{ $status }}" @selected($status === $row['status'])>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-secondary yt-btn-sm">Change</button>
                                            </form>
                                        @else
                                            <span class="yt-outcome">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5"><div class="ms-empty-state"><h4>No Learners</h4></div></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        @else
            <article class="ms-table-container">
                <div class="ms-empty-state" id="closeYearEmpty">
                    <h4>No Learners In This Section</h4>
                </div>
            </article>
        @endif
    </div>
</div>

<script>
(() => {
    document.querySelectorAll('.js-end-status').forEach((select) => {
        const cell = select.closest('tr')?.querySelector('.js-end-outcome');
        const update = () => {
            if (cell) cell.textContent = select.selectedOptions[0]?.dataset.outcome ?? '';
        };
        select.addEventListener('change', update);
        update();
    });

    const form = document.getElementById('closeYearForm');
    form?.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
})();
</script>
</body>
</html>
