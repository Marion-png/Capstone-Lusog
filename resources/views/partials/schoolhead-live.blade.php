{{--
    Keeps a School Head tab current with what other roles are writing.

    The head reads what an adviser encodes and a nurse logs, so the screen has
    to notice those writes without anybody pressing refresh. The page polls the
    same no-PII stamp the Dashboard uses (SchoolHeadPulse, scoped to this
    school) and reloads itself only when the stamp actually moves — a
    neighbouring school's entry never moves it, and never reaches these figures
    in any case, because every read is scoped to the institution.

    The Dashboard does not include this: it re-renders its panels in place from
    the metrics endpoint, which is better than a reload. This is for the tabs
    that are one server-rendered page.

    Needs $stamp.
--}}
<script>
    (function () {
        const PULSE_MS = 20000;
        const pulseUrl = @json(route('dashboard.school-head.metrics.pulse'));
        let stamp = @json($stamp);

        const pulse = async function () {
            // A hidden tab is not being read, and a page mid-print must not be
            // pulled out from under the printer.
            if (document.hidden) {
                return;
            }

            try {
                const response = await fetch(pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                if (payload.stamp && payload.stamp !== stamp) {
                    stamp = payload.stamp;
                    // Keep the filters, the year and the page the head chose.
                    window.location.reload();
                }
            } catch (error) {
                // Offline or a dropped request: the next pulse retries.
            }
        };

        window.setInterval(pulse, PULSE_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                pulse();
            }
        });
    })();
</script>
