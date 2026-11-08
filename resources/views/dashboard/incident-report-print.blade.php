{{--
    The school's Incident Report form, for paper (StudentIncidentReportController
    ::printReport / ::printBlank).

    One template, two uses: a filed report printed onto it, or the form blank
    for an incident written up by hand — the learner's own lines and the
    signatories filled in, every line about the incident left to write on. It
    is a facsimile of a printed school form, so it keeps its own register (black
    hairlines, a ruled grid, tick boxes) rather than the app's chrome, under the
    same office letterhead the masterlist prints with (SchoolLetterhead).

    The four signatures the clinic's process needs — Clinic Nurse, Class
    Adviser, Curriculum Head, School Principal — are StudentIncidentReport::
    SIGNATORIES; a name the app does not hold is a line to sign on.

    The profile loads this into a hidden frame and prints the frame; opened on
    its own, it prints itself once the seals have loaded.
--}}
@php
    $r = $report;
    $blank = $r === null;
    $exam = $r['physical_exam'] ?? [];
    $value = fn (?string $text): string => trim((string) $text);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Incident Report</title>
    <style>
        /* No page margin at all. A browser prints its own header and footer —
           the date, the page title, the web address, the page number — in the
           page margin, and with none to print them in it prints none. The
           form's own margins come from the body's padding (left and right)
           and the empty head and foot of .ir-page (top and bottom), which a
           browser repeats on every page, so a report that runs to a second
           sheet is margined there too. */
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; color: #000; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.3; }
        .ir-sheet { max-width: 186mm; margin: 0 auto; }

        .ir-letterhead { display: flex; align-items: center; gap: 12px; }
        .ir-seals { display: flex; gap: 6px; flex-shrink: 0; }
        .ir-seal { width: 64px; height: 64px; object-fit: contain; display: block; }
        .ir-seal-empty { width: 64px; height: 64px; border: 1px dashed #9aa8a0; border-radius: 50%; }
        .ir-masthead { line-height: 1.2; }
        .ir-line-republic { font-size: 8.5pt; }
        .ir-line-department, .ir-line-division { font-size: 9.5pt; }
        .ir-line-school { font-size: 14pt; font-weight: 700; color: #1A7A3A; }
        .ir-line-address { font-size: 8.5pt; min-height: 1.1em; }
        .ir-line-office { font-size: 9.5pt; font-weight: 700; }
        .ir-line-contact { font-size: 7.5pt; }
        .ir-rule { border: 0; border-top: 3px solid #1A7A3A; margin: 6px 0 9px; }

        .ir-title { text-align: center; font-size: 14pt; font-weight: 700; letter-spacing: .08em; margin: 0; }
        .ir-sub { text-align: center; font-size: 9.5pt; margin: 2px 0 9px; }

        .ir-section { margin-bottom: 8px; break-inside: avoid; }
        .ir-band { margin: 0 0 4px; padding: 3px 6px; font-size: 9pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; background: #E4EDE7; border: 1px solid #000; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 3px 6px; vertical-align: top; text-align: left; font-size: 9.5pt; }
        th { font-weight: 700; background: #F4F7F5; }
        .ir-cell { min-height: 1.35em; overflow-wrap: anywhere; white-space: pre-line; }
        .ir-center { text-align: center; }
        .ir-vitals th { text-align: center; font-size: 8.5pt; }
        .ir-vitals td { height: 26px; text-align: center; vertical-align: middle; font-size: 10.5pt; }
        .ir-unit { display: block; font-weight: 400; font-size: 7.5pt; }

        .ir-ticks { display: flex; flex-wrap: wrap; gap: 3px 16px; }
        .ir-tick { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .ir-box { width: 11px; height: 11px; border: 1px solid #000; display: inline-grid; place-items: center; font-size: 9pt; line-height: 1; font-weight: 700; flex-shrink: 0; }

        /* Lines to write on, on the blank form: ruled rows, not a pattern,
           so the first line sits a line below the cell's own border. */
        .ir-write { height: 19px; border-bottom: 1px solid #8a8a8a; }
        .ir-write:last-child { margin-bottom: 2px; }

        .ir-fdar td { height: auto; }
        .ir-fdar th:nth-child(1), .ir-fdar td:nth-child(1) { width: 17%; }
        .ir-fdar th:nth-child(2), .ir-fdar td:nth-child(2) { width: 22%; }
        .ir-note { display: grid; grid-template-columns: 92px minmax(0, 1fr); gap: 4px; }
        .ir-note + .ir-note { margin-top: 5px; }
        .ir-note b { font-weight: 700; white-space: nowrap; }
        .ir-charted { margin-top: 8px; padding-top: 5px; border-top: 1px dashed #777; font-size: 8.5pt; }
        /* The chart is the one part that can run long — four notes of up to
           2,000 characters each — so it flows on to the next sheet (its
           column heads repeat there) instead of being pushed whole on to a
           page of its own and leaving its heading stranded behind. Every
           other section still keeps together. */
        .ir-section-fdar { break-inside: auto; }
        .ir-fdar > tbody > tr { break-inside: auto; }
        .ir-band { break-after: avoid; }

        /* The four signatures on one row, in the order the report travels:
           the nurse who prepared it, the adviser, the curriculum head, the
           principal. One row keeps them on the page the report ends on. */
        .ir-sign { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-top: 14px; break-inside: avoid; }
        .ir-sign-label { font-size: 8.5pt; margin-bottom: 24px; }
        .ir-sign-name { border-bottom: 1px solid #000; min-height: 1.35em; font-size: 8.5pt; font-weight: 700; text-transform: uppercase; text-align: center; overflow-wrap: anywhere; }
        .ir-sign-title { margin-top: 2px; font-size: 8.5pt; text-align: center; }
        .ir-sign-date { margin-top: 7px; font-size: 8pt; display: flex; gap: 4px; }
        .ir-sign-date span { flex: 1; border-bottom: 1px solid #000; }

        tr { break-inside: avoid; }
        thead { display: table-header-group; }

        /* The page frame: one cell holding the form, between an empty head
           and foot that stand in for the top and bottom margins. */
        .ir-page { width: 100%; border-collapse: collapse; table-layout: auto; }
        .ir-page > thead > tr > td,
        .ir-page > tbody > tr > td,
        .ir-page > tfoot > tr > td { border: 0; padding: 0; background: none; }
        .ir-page > tbody > tr { break-inside: auto; }
        .ir-page > tfoot { display: table-footer-group; }
        .ir-page-space { height: 10mm; }

        @media print {
            body { padding: 0 12mm; }
            .ir-sheet { max-width: none; }
            .ir-line-school, .ir-rule, .ir-band, th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        /* Opened on its own rather than in the print frame: the sheet on a
           page, at the width it will print at. */
        @media screen {
            html { background: #E9EFEB; }
            .ir-page > thead, .ir-page > tfoot { display: none; }
            body { max-width: 820px; margin: 24px auto; padding: 28px; background: #fff; box-shadow: 0 1px 4px rgba(5, 46, 22, .08), 0 12px 32px rgba(5, 46, 22, .1); }
        }
    </style>
</head>
<body data-incident-sheet>
<table class="ir-page" role="presentation">
<thead><tr><td><div class="ir-page-space"></div></td></tr></thead>
<tfoot><tr><td><div class="ir-page-space"></div></td></tr></tfoot>
<tbody><tr><td>
<main class="ir-sheet">
    <header class="ir-letterhead">
        <div class="ir-seals">
            @foreach (['deped' => 'Schools Division seal', 'school' => 'School seal'] as $key => $alt)
                @if ($seals[$key])
                    <img class="ir-seal" src="{{ asset($seals[$key]) }}" alt="{{ $alt }}">
                @else
                    <span class="ir-seal-empty" aria-hidden="true"></span>
                @endif
            @endforeach
        </div>
        <div class="ir-masthead">
            @foreach ($masthead as $line)
                <div class="ir-line-{{ $line['role'] }}">{{ $line['text'] }}</div>
            @endforeach
        </div>
    </header>
    <hr class="ir-rule">

    <h1 class="ir-title">INCIDENT REPORT</h1>
    <p class="ir-sub">School Clinic &middot; S.Y. {{ $schoolYear }}</p>

    {{-- I. The learner --}}
    <section class="ir-section">
        <h2 class="ir-band">I. Learner Information</h2>
        <table class="ir-grid">
            <colgroup><col style="width:21%"><col style="width:28%"><col style="width:9%"><col style="width:15%"><col style="width:9%"><col style="width:18%"></colgroup>
            <tr>
                <th>Name of Learner</th>
                <td colspan="3"><div class="ir-cell">{{ $learner['name'] }}</div></td>
                <th>LRN</th>
                <td><div class="ir-cell">{{ $learner['lrn'] }}</div></td>
            </tr>
            <tr>
                <th>Grade &amp; Section</th>
                <td><div class="ir-cell">{{ trim($learner['grade'].($learner['section'] !== '' ? ' – '.$learner['section'] : '')) }}</div></td>
                <th>Sex</th>
                <td><div class="ir-cell">{{ $learner['sex'] }}</div></td>
                <th>Age</th>
                <td><div class="ir-cell">{{ $learner['age'] }}</div></td>
            </tr>
            <tr>
                <th>Parent / Guardian</th>
                <td colspan="5"><div class="ir-cell">{{ $learner['guardian'] }}</div></td>
            </tr>
        </table>
    </section>

    {{-- II. The incident --}}
    <section class="ir-section">
        <h2 class="ir-band">II. Incident Details</h2>
        <table class="ir-grid">
            <colgroup><col style="width:21%"><col style="width:28%"><col style="width:9%"><col style="width:15%"><col style="width:9%"><col style="width:18%"></colgroup>
            <tr>
                <th>Date of Incident</th>
                <td><div class="ir-cell">{{ $blank ? '' : \Illuminate\Support\Carbon::parse($r['occurred_at'])->format('F j, Y') }}</div></td>
                <th>Time</th>
                <td><div class="ir-cell">{{ $r['occurred_time_label'] ?? '' }}</div></td>
                <th>Place</th>
                <td><div class="ir-cell">{{ $r['location'] ?? '' }}</div></td>
            </tr>
            <tr>
                <th>Nature of Incident</th>
                <td colspan="5">
                    <div class="ir-ticks">
                        @foreach ($categories as $key => $label)
                            <span class="ir-tick"><span class="ir-box">{{ ($r['category'] ?? null) === $key ? '√' : '' }}</span>{{ $label }}</span>
                        @endforeach
                    </div>
                </td>
            </tr>
            <tr>
                <th>Severity</th>
                <td colspan="5">
                    <div class="ir-ticks">
                        @foreach ($severities as $key => $label)
                            <span class="ir-tick"><span class="ir-box">{{ ($r['severity'] ?? null) === $key ? '√' : '' }}</span>{{ $label }}</span>
                        @endforeach
                    </div>
                </td>
            </tr>
            <tr>
                <th>Witnesses</th>
                <td colspan="5"><div class="ir-cell">{{ $r['witnesses'] ?? '' }}</div></td>
            </tr>
            <tr>
                <th>Parent / Guardian Informed</th>
                <td colspan="5">
                    <div class="ir-ticks">
                        <span class="ir-tick"><span class="ir-box">{{ ! $blank && $r['guardian_notified'] ? '√' : '' }}</span>Yes</span>
                        <span class="ir-tick"><span class="ir-box">{{ ! $blank && ! $r['guardian_notified'] ? '√' : '' }}</span>No</span>
                    </div>
                </td>
            </tr>
        </table>
    </section>

    {{-- III. The physical examination taken at the time --}}
    <section class="ir-section">
        <h2 class="ir-band">III. Physical Examination</h2>
        <table class="ir-vitals">
            <thead>
                <tr>
                    @foreach ($vitals as $key => $vital)
                        <th>{{ $vital['label'] }}<span class="ir-unit">{{ $vital['unit'] }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    @foreach ($vitals as $key => $vital)
                        <td>{{ $exam[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>
        <table class="ir-grid" style="margin-top:-1px">
            <colgroup><col style="width:21%"><col style="width:18%"><col style="width:21%"><col style="width:40%"></colgroup>
            <tr>
                <th>Level of Consciousness</th>
                <td colspan="3">
                    <div class="ir-ticks">
                        @foreach ($consciousness as $key => $label)
                            <span class="ir-tick"><span class="ir-box">{{ ($exam['consciousness'] ?? '') === $key ? '√' : '' }}</span>{{ $label }}</span>
                        @endforeach
                    </div>
                </td>
            </tr>
            <tr>
                <th>Pain Score</th>
                <td><div class="ir-cell">{{ ($exam['pain_score'] ?? '') !== '' ? $exam['pain_score'].' / 10' : '' }}@if ($blank)<span style="float:right">/ 10</span>@endif</div></td>
                <th>Body Part Affected</th>
                <td><div class="ir-cell">{{ $exam['body_site'] ?? '' }}</div></td>
            </tr>
            <tr>
                <th>Findings</th>
                <td colspan="3">
                    @if ($blank)
                        @for ($line = 0; $line < 3; $line++)<div class="ir-write"></div>@endfor
                    @else
                        <div class="ir-cell">{{ $exam['findings'] ?? '' }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </section>

    {{-- IV. The chart, as it was filed --}}
    <section class="ir-section ir-section-fdar">
        <h2 class="ir-band">IV. Focus Charting (F-DAR)</h2>
        <table class="ir-fdar">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Focus</th>
                    <th>Progress Notes</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        @unless ($blank)
                            <div>{{ $r['occurred_label'] }}</div>
                            <div>{{ $r['occurred_time_label'] }}</div>
                        @endunless
                    </td>
                    <td><div class="ir-cell">{{ $blank ? '' : $r['focus_label'] }}</div></td>
                    <td>
                        @foreach (['D' => ['Data', 'description'], 'A' => ['Action', 'action_taken'], 'R' => ['Response', 'response']] as $letter => [$label, $field])
                            <div class="ir-note">
                                <b>{{ $letter }} – {{ $label }}:</b>
                                @if ($blank)
                                    <div>@for ($line = 0; $line < ($letter === 'D' ? 3 : 2); $line++)<div class="ir-write"></div>@endfor</div>
                                @else
                                    <div class="ir-cell">{{ $value($r[$field] ?? '') }}</div>
                                @endif
                            </div>
                        @endforeach

                        @unless ($blank)
                            <div class="ir-charted">
                                Charted by {{ trim(implode(', ', array_filter([$r['reported_by'], $r['reported_by_title']]))) ?: '—' }}@if ($r['filed_label']) &middot; {{ $r['filed_label'] }}@endif
                                @if ($r['edited_label'])
                                    <br>Edited {{ $r['edited_label'] }}@if ($r['edited_by']) by {{ trim(implode(', ', array_filter([$r['edited_by'], $r['edited_by_title']]))) }}@endif
                                @endif
                            </div>
                        @endunless
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    {{-- The four signatures --}}
    <section class="ir-sign" aria-label="Signatories">
        @foreach ($signatories as $signatory)
            <div class="ir-sign-block">
                <div class="ir-sign-label">{{ $signatory['label'] }}:</div>
                <div class="ir-sign-name">{{ $signatory['name'] }}</div>
                <div class="ir-sign-title">{{ $signatory['title'] }}</div>
                <div class="ir-sign-date">Date: <span></span></div>
            </div>
        @endforeach
    </section>
</main>
</td></tr></tbody>
</table>
<script>
    // Opened on its own, it prints itself; inside the profile's print frame
    // the profile does the printing.
    if (window.self === window.top) {
        window.addEventListener('load', () => window.print());
    }
</script>
</body>
</html>
