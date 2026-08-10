{{--
    Live clock pill for the top navbar, shared by every role's dashboard.
    Self-contained styles/script so it can be @included on any page
    regardless of that page's own CSS variable names.
--}}
<style>
    /* --clock-* lets a role retheme the pill (the Feeding Coordinator does,
       in lusog-theme.css); every other role keeps the fallbacks. */
    .live-clock-pill {
        display: inline-flex; align-items: center; gap: 7px;
        font-size: .74rem; font-weight: 600; color: var(--clock-ink, #166534);
        background: var(--clock-bg, #f0fdf4); border: 1px solid var(--clock-border, #bbf7d0); border-radius: 999px;
        padding: 6px 13px; white-space: nowrap; line-height: 1.2; margin-left: auto;
        font-variant-numeric: tabular-nums;
    }
    .live-clock-pill svg { width: 13px; height: 13px; flex-shrink: 0; color: var(--clock-icon, currentColor); }
    .live-clock-pill .live-clock-date { color: var(--clock-muted, #4ba374); font-weight: 500; }
    .live-clock-pill .live-clock-sep { color: var(--clock-border, #bbf7d0); }
</style>
<div class="live-clock-pill" id="liveClockPill">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="12" cy="12" r="9"/>
        <polyline points="12 7 12 12 16 14"/>
    </svg>
    <span class="live-clock-date" id="liveClockDate"></span>
    <span class="live-clock-sep">&middot;</span>
    <span id="liveClockTime"></span>
</div>
<script>
(function () {
    var dateEl = document.getElementById('liveClockDate');
    var timeEl = document.getElementById('liveClockTime');
    if (!dateEl || !timeEl) return;

    var dateFmt = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', weekday: 'short', month: 'short', day: 'numeric' });
    var timeFmt = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });

    function tick() {
        var now = new Date();
        dateEl.textContent = dateFmt.format(now);
        timeEl.textContent = timeFmt.format(now);
    }

    tick();
    setInterval(tick, 1000);
})();
</script>