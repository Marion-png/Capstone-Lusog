<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Feeding Head - SBFP Forms</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
	<style>
		*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
		:root{--g900:#14532d;--g300:#86efac;--cream:#f7f8f5;--card:#fff;--border:#e4ece7;--text-1:#0d1f14;--text-2:#3d5c47;--text-3:#7a9e87;--sidebar-w:248px;--sidebar-collapsed-w:76px;--topbar-h:64px;--radius-sm:10px;--shadow-card:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
		html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text-1);overflow:hidden}
		.sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-collapsed-w);background:var(--g900);display:flex;flex-direction:column;z-index:100;overflow:hidden;transition:width .24s ease}
		.sidebar:hover{width:var(--sidebar-w)}
		.sb-logo{padding:20px 20px 18px;position:relative;z-index:2;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:center;transition:padding .24s ease}
		.sb-logo img{width:176px;max-width:100%;height:auto;display:block;transition:width .24s ease}
		.sidebar:not(:hover) .sb-logo{padding:14px 10px}
		.sidebar:not(:hover) .sb-logo img{width:48px}
		.sb-nav{flex:1;overflow-y:auto;padding:16px 12px}
		.sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);text-decoration:none;color:rgba(255,255,255,.62);font-size:.83rem;font-weight:500;margin-bottom:2px;white-space:nowrap;overflow:hidden}
		.sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
		.sidebar:not(:hover) .sb-link{justify-content:center;font-size:0;padding:10px;gap:0}
		.sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px}
		.sb-avatar{width:34px;height:34px;border-radius:50%;background:#16a34a;display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff}
		.sb-user-name{font-size:.8rem;font-weight:600;color:#fff}
		.sidebar:not(:hover) .sb-user-name{display:none}
		.main{margin-left:var(--sidebar-collapsed-w);height:100vh;display:flex;flex-direction:column;overflow:hidden;transition:margin-left .24s ease}
		.sidebar:hover ~ .main{margin-left:var(--sidebar-w)}
		.topbar{height:var(--topbar-h);border-bottom:1px solid var(--border);background:#fff;display:flex;align-items:center;padding:0 22px}
		.topbar-bc{font-size:.76rem;color:var(--text-3);display:flex;gap:6px;align-items:center}
		.content{overflow:auto;padding:18px}
		.page-eyebrow{font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#15803d;margin-bottom:6px}
		.page-title{font-family:'DM Serif Display',serif;font-size:1.75rem;line-height:1.15}
		.page-title span{font-style:italic;color:#15803d}
		.page-sub{margin-top:5px;font-size:.8rem;color:var(--text-3)}
		.card{margin-top:14px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;box-shadow:var(--shadow-card)}
		.note{font-size:.82rem;color:var(--text-2);line-height:1.5}
		@media (max-width:780px){.sidebar{display:none}.main{margin-left:0}}
	</style>
</head>
<body>
<aside class="sidebar">
	<div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
	<nav class="sb-nav">
		<a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">Dashboard</a>
		<a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">Student Health Records</a>
		<a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">Feeding Program</a>
		<a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link active">SBFP Forms</a>
	</nav>
	<div class="sb-user">
		<div class="sb-avatar">{{ substr(auth()->user()->name ?? 'FC',0,2) }}</div>
		<div class="sb-user-name">{{ auth()->user()->name ?? 'Feeding Coordinator' }}</div>
	</div>
</aside>

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>SBFP Forms</span></div>
	</header>
	<div class="content">
		<div class="page-eyebrow">Feeding Program</div>
		<h1 class="page-title">SBFP <span>Forms</span></h1>
		<p class="page-sub">Central access to SBFP form templates and encoded submissions.</p>

		<section class="card">
			<p class="note">This tab is now available from the sidebar. You can continue using your existing forms workflow from Feeding Program while this dedicated SBFP Forms page is prepared.</p>
		</section>
	</div>
</div>
</body>
</html>
