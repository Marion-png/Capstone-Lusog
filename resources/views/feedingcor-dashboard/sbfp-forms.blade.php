<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SBFP Report Details - Feeding Coordinator - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/feeding-feed-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>
        .flash{padding:10px 14px;border-radius:10px;font-size:.82rem;margin-bottom:12px}
        .flash.ok{background:#f0fdf4;border:1px solid #dcfce7;color:#166534}
        .flash.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
        .notice{padding:10px 12px;border-radius:10px;font-size:.8rem;margin-bottom:14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
        .notice a{font-weight:700;color:#1d4ed8}
        .fieldset{border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:16px;background:#fff}
        .fieldset h3{margin:0 0 10px;font-size:.95rem;color:#0f172a}
        .field{margin-bottom:10px}
        .field label{display:block;font-size:.76rem;font-weight:600;color:#334155;margin-bottom:4px}
        .field textarea,.field input{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:.82rem}
        .field textarea{min-height:64px;resize:vertical}
        .row3{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
        .btn-primary{padding:9px 18px;border:none;border-radius:9px;background:#0d9488;color:#fff;font-weight:600;font-size:.85rem;cursor:pointer}
        table.consignee{width:100%;border-collapse:collapse}
        table.consignee td{padding:4px}
        table.consignee input{width:100%;box-sizing:border-box;padding:7px 8px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:.8rem}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">Dashboard</a>
        <a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">Feeding Program</a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">Student Health Records</a>
        <a href="{{ route('dashboard.feedingcor-baseline') }}" class="sb-link">Baseline Entry</a>
        <a href="{{ route('dashboard.feedingcor-endline') }}" class="sb-link">Endline Entry</a>
        <a href="{{ route('dashboard.feedingcor-reports') }}" class="sb-link">Reports</a>
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link active">SBFP Forms</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ strtoupper(substr(session('active_name', 'FC'), 0, 2)) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'Feeding Coordinator') }}</div>
            <div class="sb-user-role" style="font-size:.68rem;color:var(--g300);line-height:1.2;">{{ session('active_school_name', 'No school assigned') }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">&#x23Fb;</button>
        </form>
    </div>
</aside>

<div class="main">
    <div class="content">
        <div class="page-eyebrow">Feeding Program</div>
        <h1 class="page-title">SBFP Report <span>Details</span></h1>
        <p class="page-sub">The report figures (masterlist, nutrition summary, attendance, baseline vs endline) are computed automatically. This page is only for the parts that can't be computed — narrative, milk consignees and signatories — for SY {{ $schoolYear }}.</p>

        @if (session('success'))<div class="flash ok">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash err">{{ session('error') }}</div>@endif

        <div class="notice">Looking for the actual report data? It's all on the <a href="{{ route('dashboard.feedingcor-reports') }}">Reports</a> screen — read-only and always up to date.</div>

        <form method="POST" action="{{ route('feedingcor.report-details.save') }}">
            @csrf

            <div class="fieldset">
                <h3>Narrative Report</h3>
                @foreach (['introduction' => 'Introduction', 'background' => 'Background and Rationale', 'implementation' => 'Implementation', 'results' => 'Results and Impact', 'conclusion' => 'Conclusion and Recommendation'] as $key => $label)
                    <div class="field">
                        <label for="narr_{{ $key }}">{{ $label }}</label>
                        <textarea id="narr_{{ $key }}" name="narrative[{{ $key }}]" placeholder="Write the {{ strtolower($label) }} here...">{{ $narrative[$key] ?? '' }}</textarea>
                    </div>
                @endforeach
            </div>

            <div class="fieldset">
                <h3>Milk Component — Authorized Consignees</h3>
                <table class="consignee">
                    <thead><tr><th>Name</th><th>Designation</th><th>Tel. No.</th><th>Mobile No.</th><th>Email</th></tr></thead>
                    <tbody>
                        @for ($i = 0; $i < max(3, count($consignees)); $i++)
                            @php $c = $consignees[$i] ?? []; @endphp
                            <tr>
                                <td><input type="text" name="consignees[{{ $i }}][name]" value="{{ $c['name'] ?? '' }}"></td>
                                <td><input type="text" name="consignees[{{ $i }}][designation]" value="{{ $c['designation'] ?? '' }}"></td>
                                <td><input type="text" name="consignees[{{ $i }}][tel]" value="{{ $c['tel'] ?? '' }}"></td>
                                <td><input type="text" name="consignees[{{ $i }}][mobile]" value="{{ $c['mobile'] ?? '' }}"></td>
                                <td><input type="email" name="consignees[{{ $i }}][email]" value="{{ $c['email'] ?? '' }}"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            <div class="fieldset">
                <h3>Signatories</h3>
                <div class="row3">
                    <div class="field"><label>Prepared by (name)</label><input type="text" name="signatories[prepared_name]" value="{{ $signatories['prepared_name'] ?? '' }}"></div>
                    <div class="field"><label>Prepared by (role)</label><input type="text" name="signatories[prepared_role]" value="{{ $signatories['prepared_role'] ?? 'Feeding Coordinator' }}"></div>
                    <div class="field"><label>Verified by (name)</label><input type="text" name="signatories[verified_name]" value="{{ $signatories['verified_name'] ?? '' }}"></div>
                    <div class="field"><label>Verified by (role)</label><input type="text" name="signatories[verified_role]" value="{{ $signatories['verified_role'] ?? '' }}"></div>
                    <div class="field"><label>Noted by (name)</label><input type="text" name="signatories[noted_name]" value="{{ $signatories['noted_name'] ?? '' }}"></div>
                    <div class="field"><label>Noted by (role)</label><input type="text" name="signatories[noted_role]" value="{{ $signatories['noted_role'] ?? 'Principal' }}"></div>
                </div>
            </div>

            <button type="submit" class="btn-primary">Save Report Details</button>
        </form>
    </div>
</div>
@includeIf('partials.sidebar-hover-pin')
</body>
</html>
