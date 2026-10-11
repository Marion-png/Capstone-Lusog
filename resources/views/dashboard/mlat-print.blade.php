{{--
    One sheet of the Mandatory Learner's Health Assessment Tool, for paper.

    Laid out from App\Support\MlatWorkbook — the model the .xlsx is written
    from — through MlatExportController::printRows(), so the printed sheet and
    the downloaded one are the same form: the same columns in the same
    proportions, the same teal bands and shaded heading rows, the same merges,
    the same ruled table. Nothing here decides what a cell says.

    The student profile loads this into a hidden frame and prints the frame;
    opened on its own, it prints itself.
--}}
@php
    $landscape = $sheet['orientation'] !== 'portrait';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $sheet['label'] }} - {{ $learner }}</title>
    <style>
        @page { size: {{ $landscape ? 'landscape' : 'portrait' }}; margin: 10mm; }

        /* The workbook's own colours (MlatExportController::BAND / HEAD). */
        :root { --mlat-band: #0F6E62; --mlat-head: #DCE6F1; --mlat-rule: #000; }

        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; color: #000; }
        body {
            font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.3;
            /* The bands are white type on teal: a printer that dropped the
               fill would drop the section headings with it. */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .mlat-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        /* pre-wrap keeps the spacing the form types between tick boxes
           ("[ ✓ ] Male   [   ] Female") and still wraps a long value. */
        .mlat-sheet td { padding: 2.5pt 5pt; vertical-align: bottom; white-space: pre-wrap; overflow-wrap: break-word; }
        /* A date moves down a line whole; the browser would otherwise break
           it at a hyphen ("2026-" / "08-05"). */
        .mlat-date { white-space: nowrap; }

        .r-title td { background: var(--mlat-band); color: #fff; font-weight: 700; font-size: 12pt; text-align: center; padding: 5pt; }
        .r-subtitle td { color: var(--mlat-band); font-weight: 700; text-align: center; }
        .r-band td { background: var(--mlat-band); color: #fff; font-weight: 700; }
        .r-head td { background: var(--mlat-head); font-weight: 700; }
        .is-ruled td { border: .75pt solid var(--mlat-rule); }
        /* A label beside several lines of text belongs at the top of them. */
        .is-wrap td { vertical-align: top; }
        .is-blank td { height: 8pt; padding: 0; }

        tr { break-inside: avoid; }

        /* Opened on its own rather than in the print frame: the sheet on a
           page, at the width it will print at. */
        @media screen {
            html { background: #E9EFEB; }
            body {
                max-width: {{ $landscape ? '1120px' : '820px' }};
                margin: 24px auto;
                padding: 28px;
                background: #fff;
                box-shadow: 0 1px 4px rgba(5, 46, 22, .08), 0 12px 32px rgba(5, 46, 22, .1);
            }
        }
    </style>
</head>
<body data-mlat-sheet="{{ $sheet['label'] }}">
    <table class="mlat-sheet">
        <colgroup>
            @foreach ($widths as $width)
                <col style="width: {{ $width }}%">
            @endforeach
        </colgroup>
        <tbody>
            @foreach ($rows as $row)
                <tr class="r-{{ $row['register'] }}{{ $row['ruled'] ? ' is-ruled' : '' }}{{ $row['wrap'] ? ' is-wrap' : '' }}{{ $row['blank'] ? ' is-blank' : '' }}">
                    @foreach ($row['cells'] as $cell)
                        {{-- Escaped first; only the digits-and-hyphens of a date are wrapped after. --}}
                        <td @if ($cell['span'] > 1) colspan="{{ $cell['span'] }}" @endif>{!! preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', '<span class="mlat-date">$0</span>', e($cell['text'])) !!}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>
        // In the profile's print frame the profile calls print; opened on its
        // own, the page does.
        if (window.self === window.top) {
            window.addEventListener('load', () => window.print());
        }
    </script>
</body>
</html>
