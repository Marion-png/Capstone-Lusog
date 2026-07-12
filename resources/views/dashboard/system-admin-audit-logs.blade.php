<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail | SIGLA</title>
    <style>
        :root{--bg:#f7f8f5;--card:#fff;--border:#e4ece7;--text:#0d1f14;--muted:#6f8c7a;--g900:#14532d;--g700:#15803d;--g300:#86efac;--g100:#dcfce7;--g50:#f0fdf4;--red:#ef4444}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',system-ui,sans-serif}
        body{background:var(--bg);color:var(--text);min-height:100vh}
        .topbar{background:var(--g900);color:#fff;padding:14px 28px;display:flex;align-items:center;gap:14px}
        .topbar a{color:var(--g300);text-decoration:none;font-size:.85rem}
        .topbar h1{font-size:1.05rem;font-weight:700}
        .content{max-width:1280px;margin:0 auto;padding:26px 20px 60px}
        .sub{color:var(--muted);font-size:.85rem;margin-bottom:18px}
        .filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
        .filters select,.filters input{padding:8px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:.83rem;background:#fff;color:var(--text)}
        .filters button{padding:8px 16px;border:none;border-radius:9px;background:var(--g700);color:#fff;font-size:.83rem;font-weight:600;cursor:pointer}
        .filters a{align-self:center;font-size:.8rem;color:var(--muted)}
        .card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
        table{width:100%;border-collapse:collapse;font-size:.8rem}
        th{background:var(--g50);color:var(--g900);text-align:left;padding:10px 12px;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
        td{padding:9px 12px;border-bottom:1px solid var(--border);vertical-align:top;color:#1d3c31}
        tr:last-child td{border-bottom:none}
        .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.7rem;font-weight:700;background:var(--g100);color:var(--g900);white-space:nowrap}
        .pill.warn{background:#fee2e2;color:#991b1b}
        .muted{color:var(--muted)}
        details summary{cursor:pointer;color:var(--g700);font-size:.74rem}
        details pre{margin-top:6px;background:var(--g50);border:1px solid var(--border);border-radius:8px;padding:8px;font-size:.7rem;white-space:pre-wrap;word-break:break-all;max-width:420px;max-height:220px;overflow:auto}
        .empty{padding:34px;text-align:center;color:var(--muted);font-size:.85rem}
    </style>
</head>
<body>
    <header class="topbar">
        <a href="{{ route('dashboard.system-admin') }}">&larr; Control Center</a>
        <h1>Audit Trail</h1>
    </header>
    <div class="content">
        <p class="sub">Every access and action performed on personal and sensitive personal information — who, what, when, and from where. Entries are append-only; change payloads are stored encrypted. Showing the latest 200 matching entries.</p>

        <form class="filters" method="GET" action="{{ route('dashboard.system-admin.audit-logs') }}">
            <select name="action">
                <option value="">All actions</option>
                @foreach ($actions as $actionOption)
                    <option value="{{ $actionOption }}" {{ $filterAction === $actionOption ? 'selected' : '' }}>{{ $actionOption }}</option>
                @endforeach
            </select>
            <input type="text" name="username" value="{{ $filterUsername }}" placeholder="Filter by username...">
            <button type="submit">Filter</button>
            <a href="{{ route('dashboard.system-admin.audit-logs') }}">Clear</a>
        </form>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Subject</th>
                        <th>Request</th>
                        <th>IP</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td style="white-space:nowrap;">{{ $log->created_at?->format('M d, Y H:i:s') }}</td>
                            <td>
                                {{ $log->actor_name ?: '—' }}
                                <div class="muted">{{ $log->actor_username }} {{ $log->actor_role ? '(' . $log->actor_role . ')' : '' }}</div>
                            </td>
                            <td><span class="pill {{ str_contains($log->action, 'failed') || $log->action === 'deleted' ? 'warn' : '' }}">{{ $log->action }}</span></td>
                            <td>{{ $log->description }}</td>
                            <td class="muted">{{ $log->subject_type ? $log->subject_type . ($log->subject_id ? ' #' . $log->subject_id : '') : '—' }}</td>
                            <td class="muted">{{ $log->http_method }} {{ $log->route_name ?: parse_url((string) $log->url, PHP_URL_PATH) }}</td>
                            <td class="muted">{{ $log->ip_address }}</td>
                            <td>
                                @if ($log->details)
                                    <details>
                                        <summary>view</summary>
                                        <pre>{{ json_encode($log->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">No audit entries match the current filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
