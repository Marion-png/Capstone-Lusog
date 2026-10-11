{{--
    Print and Download for the Mandatory Learner's Health Assessment Tool, one
    sheet at a time. Shared by both student profiles — the nurse's (clinic
    roles) and the class adviser's — so the two desks offer the form the same
    way.

    Both menus read one model (App\Support\MlatWorkbook): Download writes it as
    an .xlsx, Print lays it out as the sheet and prints it through a hidden
    frame, so the profile stays open behind the print dialog.

    $mlatId  — id of the wrapper, for a page that sets the learner later.
    $mlatLrn — the learner. Left empty, the URLs carry __LRN__ and the menus
               stay hidden until MlatMenus.setLearner() fills them in (the
               nurse's profile is one dialog reused for every learner).

    The page also includes partials/mlat-menus-script with its other scripts.
--}}
@php
    $mlatId = $mlatId ?? 'mlatMenus';
    $mlatLrn = trim((string) ($mlatLrn ?? ''));
    $mlatTemplate = '__LRN__';
    $mlatUrl = fn (string $route, int $sheet, string $lrn): string => route($route, ['lrn' => $lrn, 'sheet' => $sheet]);
@endphp
@once
<style>
    /* The wrapper is invisible to layout: each menu is an item of the
       actions bar it sits in, spaced like the buttons beside it. */
    .mlat-menus { display: contents; }
    .mlat-menus[hidden] { display: none; }
    .mlat-menu { position: relative; display: inline-flex; }
    .mlat-menu-toggle svg.mlat-caret { width: 14px; height: 14px; margin-left: -2px; transition: transform .16s ease; }
    .mlat-menu-toggle[aria-expanded="true"] svg.mlat-caret { transform: rotate(180deg); }
    .mlat-menu-toggle.is-busy { cursor: progress; opacity: .72; }
    .mlat-menu-list {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        z-index: 60;
        min-width: 100%;
        padding: 5px;
        background: #fff;
        border: 1px solid var(--lg-border-strong, #C7DCCE);
        border-radius: 10px;
        box-shadow: 0 14px 34px rgba(16, 32, 24, .16);
        animation: mlatMenuIn .12s ease-out;
    }
    .mlat-menu-list[hidden] { display: none; }
    @keyframes mlatMenuIn {
        from { opacity: 0; transform: translateY(-4px); }
        to   { opacity: 1; transform: none; }
    }
    /* Tinted, never underlined — nothing in this app rules a line through a word. */
    .mlat-menu-item {
        display: block;
        padding: 9px 14px;
        border-radius: 7px;
        font-family: inherit;
        font-size: .88rem;
        font-weight: 600;
        line-height: 1.3;
        color: var(--lg-ink, #1F2D25);
        text-decoration: none;
        white-space: nowrap;
    }
    .mlat-menu-item:hover, .mlat-menu-item:focus-visible {
        background: var(--lg-mint, #E7F5EC);
        color: var(--lg-emerald-deep, #126B3A);
        outline: none;
    }
    @media (prefers-reduced-motion: reduce) {
        .mlat-menu-list { animation: none; }
        .mlat-menu-toggle svg.mlat-caret { transition: none; }
    }
    @media print { .mlat-menus { display: none !important; } }
</style>
@endonce

<div class="mlat-menus" id="{{ $mlatId }}" data-mlat-menus @if ($mlatLrn === '') hidden @endif>
    <div class="mlat-menu">
        <button type="button" class="btn btn-secondary mlat-menu-toggle" id="{{ $mlatId }}-print"
                aria-haspopup="menu" aria-expanded="false" aria-controls="{{ $mlatId }}-print-list">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print
            <svg class="mlat-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="mlat-menu-list" id="{{ $mlatId }}-print-list" role="menu" aria-labelledby="{{ $mlatId }}-print" hidden>
            @foreach (\App\Support\MlatWorkbook::SHEETS as $sheetNumber => $sheetLabel)
                {{-- A real link, so without script it still opens the sheet,
                     which prints itself. --}}
                <a role="menuitem" class="mlat-menu-item" tabindex="-1" target="_blank" rel="noopener"
                   href="{{ $mlatUrl('student-mlat.print', $sheetNumber, $mlatLrn !== '' ? $mlatLrn : $mlatTemplate) }}"
                   data-mlat-template="{{ $mlatUrl('student-mlat.print', $sheetNumber, $mlatTemplate) }}"
                   data-mlat-print>{{ $sheetLabel }}</a>
            @endforeach
        </div>
    </div>

    <div class="mlat-menu">
        <button type="button" class="btn btn-secondary mlat-menu-toggle" id="{{ $mlatId }}-download"
                aria-haspopup="menu" aria-expanded="false" aria-controls="{{ $mlatId }}-download-list">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download
            <svg class="mlat-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="mlat-menu-list" id="{{ $mlatId }}-download-list" role="menu" aria-labelledby="{{ $mlatId }}-download" hidden>
            @foreach (\App\Support\MlatWorkbook::SHEETS as $sheetNumber => $sheetLabel)
                <a role="menuitem" class="mlat-menu-item" tabindex="-1"
                   href="{{ $mlatUrl('student-mlat.download', $sheetNumber, $mlatLrn !== '' ? $mlatLrn : $mlatTemplate) }}"
                   data-mlat-template="{{ $mlatUrl('student-mlat.download', $sheetNumber, $mlatTemplate) }}"
                   data-mlat-download>{{ $sheetLabel }}</a>
            @endforeach
        </div>
    </div>
</div>

