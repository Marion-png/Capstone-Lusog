{{--
    Incident Report panel behaviour. Expects $lrn.

    Every value rendered here was typed by a person about a child, so the
    list is built from DOM nodes and never innerHTML — a template string
    would run any markup inside a description.
--}}
<script>
(() => {
    const form = document.getElementById('incidentForm');
    const list = document.getElementById('vpIncidentsList');
    if (!form || !list) return;

    const newBtn = document.getElementById('incidentNewBtn');
    const cancelBtn = document.getElementById('incidentCancel');
    const submitBtn = document.getElementById('incidentSubmit');
    const errorBox = document.getElementById('incidentError');
    const countLabel = document.getElementById('vpIncidentsCount');
    const tabBadge = document.getElementById('vpIncidentsTabBadge');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const setOpen = (open) => {
        form.hidden = !open;
        if (newBtn) newBtn.hidden = open;
        if (open) document.getElementById('incidentDate')?.focus();
    };

    const showError = (message) => {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.hidden = message === '';
    };

    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    const renderRow = (report) => {
        const card = el('article', 'incident-card');
        card.dataset.id = report.id;

        const head = el('div', 'incident-card-head');
        head.append(
            el('span', 'incident-date', report.occurred_label || report.occurred_at || '-'),
            el('span', 'incident-type', report.category_label),
            el('span', 'incident-sev incident-sev-' + report.severity, report.severity_label),
        );

        if (report.guardian_notified) {
            head.appendChild(el('span', 'incident-flag', 'Guardian informed'));
        }

        // Withdrawing is for a report filed by mistake. The delete is audited,
        // so a withdrawn report still leaves a record that it existed.
        const remove = el('button', 'incident-remove', 'Withdraw');
        remove.type = 'button';
        remove.dataset.remove = report.id;
        head.appendChild(remove);

        card.appendChild(head);
        card.appendChild(el('p', 'incident-desc', report.description));

        const facts = el('div', 'incident-facts');
        const addFact = (label, value) => {
            if (!value) return;
            const row = el('div');
            row.append(el('span', null, label), el('b', null, value));
            facts.appendChild(row);
        };
        addFact('Where:', report.location);
        addFact('Action taken:', report.action_taken);
        addFact('Witnesses:', report.witnesses);
        addFact('Filed by:', [report.reported_by, report.filed_label].filter(Boolean).join(' · '));

        if (facts.childElementCount > 0) card.appendChild(facts);

        return card;
    };

    const render = (reports) => {
        list.textContent = '';

        const count = reports.length;
        if (countLabel) countLabel.textContent = count === 1 ? '1 report' : count + ' reports';
        if (tabBadge) tabBadge.textContent = String(count);

        if (count === 0) {
            list.appendChild(el('p', 'sp-note', 'No incidents have been reported for this learner.'));
            return;
        }

        reports.forEach((report) => list.appendChild(renderRow(report)));
    };

    const load = async () => {
        try {
            const response = await fetch(form.dataset.index, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            render(Array.isArray(data.reports) ? data.reports : []);
        } catch (_) {
            // Leave whatever is on screen rather than blanking the history.
        }
    };

    newBtn?.addEventListener('click', () => { showError(''); setOpen(true); });
    cancelBtn?.addEventListener('click', () => { form.reset(); showError(''); setOpen(false); });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showError('');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const response = await fetch(form.dataset.store, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: new FormData(form),
            });

            if (response.status === 422) {
                const data = await response.json();
                const first = Object.values(data.errors || {})[0];
                showError(Array.isArray(first) ? first[0] : 'Please check the form and try again.');
                return;
            }

            if (!response.ok) {
                showError('The report could not be saved. Please try again.');
                return;
            }

            const data = await response.json();
            render(Array.isArray(data.reports) ? data.reports : []);
            form.reset();
            setOpen(false);
        } catch (_) {
            showError('The report could not be saved. Please try again.');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button) return;

        if (!window.confirm('Withdraw this incident report? This is recorded in the audit trail.')) return;

        try {
            const response = await fetch(
                @json(url('health-records/students/'.$lrn.'/incidents')) + '/' + button.dataset.remove,
                { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } }
            );
            if (!response.ok) return;
            const data = await response.json();
            render(Array.isArray(data.reports) ? data.reports : []);
        } catch (_) {
            // Nothing to do — the row stays until the next successful read.
        }
    });

    load();
})();
</script>
