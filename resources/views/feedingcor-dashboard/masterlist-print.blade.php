<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>{{ $title }} &middot; S.Y. {{ $schoolYear }}</title>
    {{-- The masterlist set for paper: the school's office letterhead — the
         two seals side by side and its lines beside them, a green rule under
         the block — then the title, the ruled table padded to the form's
         twenty lines and the signature block. The workbook export is the same
         form (MasterlistSheet), read from the same rows and the same
         SchoolLetterhead::masthead(). It is a facsimile of printed paper, so
         it keeps its own register rather than the app's chrome. --}}
    <style>
        @page { size: A4 portrait; margin: 12mm 14mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; color: #000; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; padding: 16px; }
        .ml-sheet { max-width: 186mm; margin: 0 auto; }

        .ml-letterhead { display: flex; align-items: center; gap: 12px; }
        .ml-seals { display: flex; gap: 6px; flex-shrink: 0; }
        .ml-seal { width: 78px; height: 78px; object-fit: contain; display: block; }
        .ml-seal-empty { width: 78px; height: 78px; border: 1px dashed #9aa8a0; border-radius: 50%; }
        .ml-masthead { line-height: 1.22; }
        .ml-line-republic { font-size: 9pt; }
        .ml-line-department, .ml-line-division { font-size: 10pt; }
        .ml-line-school { font-size: 15pt; font-weight: 700; color: #1A7A3A; letter-spacing: .01em; }
        .ml-line-address { font-size: 9pt; min-height: 1.1em; }
        .ml-line-office { font-size: 10pt; font-weight: 700; }
        .ml-line-contact { font-size: 8pt; }
        .ml-rule { border: 0; border-top: 3px solid #1A7A3A; margin: 8px 0 16px; }

        .ml-title { text-align: center; font-size: 12pt; font-weight: 700; margin: 0; }
        .ml-year { text-align: center; font-size: 10pt; margin: 4px 0 12px; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { border: 1px solid #000; padding: 4px 6px; font-size: 10pt; height: 22px; vertical-align: middle; }
        th { font-weight: 700; text-align: left; }
        .ml-no { width: 9%; text-align: center; }
        .ml-grade { width: 11%; text-align: center; }
        .ml-section { width: 28%; }

        .ml-sign { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 28px; break-inside: avoid; }
        .ml-sign-label { margin-bottom: 26px; }
        .ml-sign-name { border-bottom: 1px solid #000; min-height: 1.3em; font-weight: 700; max-width: 260px; }
        .ml-sign-role { margin-top: 3px; }

        @media print {
            body { padding: 0; }
            .ml-sheet { max-width: none; }
            .ml-line-school, .ml-rule { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body data-masterlist-sheet>
<main class="ml-sheet">
    <header class="ml-letterhead">
        <div class="ml-seals">
            @foreach (['deped' => 'Schools Division seal', 'school' => 'School seal'] as $key => $alt)
                @if ($seals[$key])
                    <img class="ml-seal" src="{{ asset($seals[$key]) }}" alt="{{ $alt }}">
                @else
                    <span class="ml-seal-empty" aria-hidden="true"></span>
                @endif
            @endforeach
        </div>
        <div class="ml-masthead">
            @foreach ($masthead as $line)
                <div class="ml-line-{{ $line['role'] }}">{{ $line['text'] }}</div>
            @endforeach
        </div>
    </header>
    <hr class="ml-rule">

    <h1 class="ml-title">{{ $title }}</h1>
    <p class="ml-year">S.Y. {{ $schoolYear }}</p>

    <table>
        <thead>
            <tr>
                <th class="ml-no">No.</th>
                <th>Name</th>
                <th class="ml-grade">Grade</th>
                <th class="ml-section">Section</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $index => $row)
                <tr>
                    <td class="ml-no">{{ $index + 1 }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="ml-grade">{{ $row['grade'] }}</td>
                    <td class="ml-section">{{ $row['section'] }}</td>
                </tr>
            @endforeach
            {{-- The form's twenty ruled lines, so a short list is still a
                 sheet that can be written on. --}}
            @for ($blank = count($rows); $blank < $minRows; $blank++)
                <tr>
                    <td class="ml-no">{{ $blank + 1 }}</td>
                    <td></td>
                    <td class="ml-grade"></td>
                    <td class="ml-section"></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <section class="ml-sign">
        <div>
            <div class="ml-sign-label">Prepared by:</div>
            <div class="ml-sign-name">{{ $preparedBy }}</div>
            <div class="ml-sign-role">{{ $preparedRole }}</div>
        </div>
        <div>
            <div class="ml-sign-label">Noted by:</div>
            <div class="ml-sign-name"></div>
            <div class="ml-sign-role">Principal</div>
        </div>
    </section>
</main>
</body>
</html>
