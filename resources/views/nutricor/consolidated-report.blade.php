@extends('nutricor.layout')

@section('title', 'Consolidated Nutritional Assessment Report - SIGLA')
@section('crumb', 'Consolidated Report')
@section('page_title')Consolidated <span>Nutritional Assessment</span>@endsection
@section('page_subtitle', 'School-wide baseline and endline summary across all grade levels and sections.')

@section('content')

{{-- ── Meta bar ──────────────────────────────────────────────────────────── --}}
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
    <div style="font-size:.76rem;color:var(--text-3);">
        <strong style="color:var(--text-2);">School:</strong>
        {{ $schoolName ?? '(All schools)' }}
        &nbsp;|&nbsp;
        <strong style="color:var(--text-2);">As of:</strong>
        {{ now()->format('F j, Y, g:i A') }}
    </div>
    <a href="{{ route('dashboard.nutricor-consolidated') }}"
       class="btn" style="font-size:.72rem;padding:6px 10px;">
        <i class="fas fa-sync-alt"></i> Refresh
    </a>
</div>

{{-- ── Enrolment summary cards ──────────────────────────────────────────── --}}
<div class="grid-3" style="margin-bottom:14px;">
    <div class="card stat" style="border-left-color:#15803d;">
        <div class="label">Total Students Assessed</div>
        <div class="num">{{ number_format($totalStudents) }}</div>
        <div class="hint">With at least one baseline or endline record</div>
    </div>
    <div class="card stat" style="border-left-color:#0369a1;">
        <div class="label">Baseline Records</div>
        <div class="num">{{ number_format($baselineTotal) }}</div>
        <div class="hint">Students with a baseline nutritional status</div>
    </div>
    <div class="card stat" style="border-left-color:#7c3aed;">
        <div class="label">Endline Records</div>
        <div class="num">{{ number_format($endlineTotal) }}</div>
        <div class="hint">Students with an endline nutritional status</div>
    </div>
</div>

@if ($totalStudents === 0)
    <div class="card" style="padding:32px;text-align:center;color:var(--text-3);">
        <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
        No nutritional assessment records found{{ $schoolName ? ' for ' . $schoolName : '' }}.
        Class Advisers must submit health card data first.
    </div>
@else

{{-- ── School-wide status summary table ───────────────────────────────────── --}}
<div class="card" style="margin-bottom:14px;">
    <div class="card-head">
        <i class="fas fa-chart-bar" style="margin-right:6px;color:#15803d;"></i>
        Nutritional Status Summary — School-Wide
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" style="width:200px;vertical-align:bottom;">Nutritional Status</th>
                        <th colspan="2" style="text-align:center;background:#eff6ff;color:#1d4ed8;">
                            Baseline &nbsp;<span style="font-weight:400;font-size:.65rem;">(n={{ number_format($baselineTotal) }})</span>
                        </th>
                        <th colspan="2" style="text-align:center;background:#faf5ff;color:#6d28d9;">
                            Endline &nbsp;<span style="font-weight:400;font-size:.65rem;">(n={{ number_format($endlineTotal) }})</span>
                        </th>
                        <th style="text-align:center;width:90px;background:#f0fdf4;color:#166534;">Change</th>
                    </tr>
                    <tr>
                        <th style="background:#eff6ff;color:#1d4ed8;text-align:right;">No.</th>
                        <th style="background:#eff6ff;color:#1d4ed8;text-align:right;">%</th>
                        <th style="background:#faf5ff;color:#6d28d9;text-align:right;">No.</th>
                        <th style="background:#faf5ff;color:#6d28d9;text-align:right;">%</th>
                        <th style="background:#f0fdf4;color:#166534;text-align:center;">±</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categories as $cat)
                        @php
                            $bl   = $baselineCounts[$cat];
                            $el   = $endlineCounts[$cat];
                            $diff = $el['count'] - $bl['count'];

                            $pillClass = match(true) {
                                in_array($cat, ['Severely Wasted', 'Wasted']) => 'bad',
                                $cat === 'Underweight' => 'warn',
                                $cat === 'Normal'      => 'ok',
                                default                => 'warn',
                            };
                        @endphp
                        <tr>
                            <td>
                                <span class="pill {{ $pillClass }}">{{ $cat }}</span>
                            </td>
                            <td style="text-align:right;">{{ number_format($bl['count']) }}</td>
                            <td style="text-align:right;color:#6b7280;">{{ $bl['percent'] }}%</td>
                            <td style="text-align:right;">{{ number_format($el['count']) }}</td>
                            <td style="text-align:right;color:#6b7280;">{{ $el['percent'] }}%</td>
                            <td style="text-align:center;">
                                @if ($diff > 0)
                                    <span style="color:#dc2626;font-weight:700;">+{{ $diff }}</span>
                                @elseif ($diff < 0)
                                    <span style="color:#16a34a;font-weight:700;">{{ $diff }}</span>
                                @else
                                    <span style="color:#9ca3af;">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f9fbfa;font-weight:700;">
                        <td>TOTAL</td>
                        <td style="text-align:right;">{{ number_format($baselineTotal) }}</td>
                        <td style="text-align:right;color:#6b7280;">100%</td>
                        <td style="text-align:right;">{{ number_format($endlineTotal) }}</td>
                        <td style="text-align:right;color:#6b7280;">100%</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

{{-- ── Per-section breakdown ────────────────────────────────────────────── --}}
@if ($sectionBreakdown->isNotEmpty())
<div class="card">
    <div class="card-head">
        <i class="fas fa-layer-group" style="margin-right:6px;color:#15803d;"></i>
        Breakdown by Grade Level / Section
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" style="width:200px;vertical-align:bottom;">Grade / Section</th>
                        <th rowspan="2" style="text-align:right;vertical-align:bottom;">Enrolled</th>
                        {{-- Baseline sub-headers --}}
                        @foreach ($categories as $cat)
                            <th style="text-align:right;background:#eff6ff;color:#1d4ed8;font-size:.62rem;white-space:nowrap;">
                                {{ $cat }}<br><span style="font-weight:400;">(BL)</span>
                            </th>
                        @endforeach
                        {{-- Endline sub-headers --}}
                        @foreach ($categories as $cat)
                            <th style="text-align:right;background:#faf5ff;color:#6d28d9;font-size:.62rem;white-space:nowrap;">
                                {{ $cat }}<br><span style="font-weight:400;">(EL)</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sectionBreakdown as $section => $data)
                        <tr>
                            <td style="font-weight:600;">{{ $section ?: '(Unspecified)' }}</td>
                            <td style="text-align:right;">{{ $data['total'] }}</td>
                            @foreach ($categories as $cat)
                                <td style="text-align:right;background:#f8fbff;">
                                    {{ $data['baseline'][$cat] > 0 ? $data['baseline'][$cat] : '—' }}
                                </td>
                            @endforeach
                            @foreach ($categories as $cat)
                                <td style="text-align:right;background:#fbf8ff;">
                                    {{ $data['endline'][$cat] > 0 ? $data['endline'][$cat] : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f9fbfa;font-weight:700;">
                        <td>TOTAL</td>
                        <td style="text-align:right;">{{ $sectionBreakdown->sum('total') }}</td>
                        @foreach ($categories as $cat)
                            <td style="text-align:right;background:#f0f6ff;">{{ $baselineCounts[$cat]['count'] > 0 ? $baselineCounts[$cat]['count'] : '—' }}</td>
                        @endforeach
                        @foreach ($categories as $cat)
                            <td style="text-align:right;background:#f7f0ff;">{{ $endlineCounts[$cat]['count'] > 0 ? $endlineCounts[$cat]['count'] : '—' }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
        <p style="padding:10px 14px;font-size:.68rem;color:var(--text-3);">
            BL = Baseline &nbsp;|&nbsp; EL = Endline &nbsp;|&nbsp;
            Change column: negative (green) means fewer students in that category at endline — an improvement for Severely Wasted / Wasted.
        </p>
    </div>
</div>
@endif

@endif {{-- totalStudents --}}

@endsection
