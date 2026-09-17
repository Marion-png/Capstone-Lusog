{{--
    Behaviour for partials/student-incidents-panel. Exposes window.StudentIncidents:

      StudentIncidents.load(lrn)     fetch and render that learner's reports

    $lrn is optional. The adviser's profile is a page and can pass its learner
    straight in; the nurse's is a dialog whose learner changes with every row,
    so the URLs are built from templates the same way partials/student-documents
    -script does it.

    Every value rendered here was typed by a person about a child, so the list
    is built from DOM nodes and never innerHTML — a template string would run
    any markup inside a description.
--}}
<script>
window.StudentIncidents = (() => {
    const list = document.getElementById('vpIncidentsList');
    if (!list) return { load: () => {} };

    const form = document.getElementById('incidentForm');
    const readOnlyBox = document.getElementById('incidentReadOnly');
    const newBtn = document.getElementById('incidentNewBtn');
    const cancelBtn = document.getElementById('incidentCancel');
    const submitBtn = document.getElementById('incidentSubmit');
    const errorBox = document.getElementById('incidentError');
    const countLabel = document.getElementById('vpIncidentsCount');
    const tabBadge = document.getElementById('vpIncidentsTabBadge');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // {lrn} is filled in per learner, because the nurse's profile is a modal
    // that serves whichever row was clicked.
    const URL_TEMPLATES = {
        index: @json(route('student-incidents.index', ['lrn' => '__LRN__'])),
        store: @json(route('student-incidents.store', ['lrn' => '__LRN__'])),
    };

    const urlFor = (kind, lrn) => URL_TEMPLATES[kind].replace('__LRN__', encodeURIComponent(lrn));

    // The page may name its learner at render time (the adviser's profile) or
    // hand one over on every open (the nurse's dialog).
    let currentLrn = @json((string) ($lrn ?? ''));

    // Whether this session may file. The server decides and says so on every
    // list; until it has answered, the panel is whatever the page rendered.
    let canFile = !!form;

    const setOpen = (open) => {
        if (!form) return;
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

    // One line of the Progress Notes: "D:" and what was written under it.
    const noteLine = (letter, label, value) => {
        const line = el('div', 'fdar-note');
        const tag = el('span', 'fdar-note-tag');
        tag.append(el('b', null, letter), el('span', null, label));
        line.append(tag, el('p', 'fdar-note-value', value));
        return line;
    };

    // The filed report, read back as the F-DAR sheet it was charted on: one
    // table, three columns — Date/Time | Focus | Progress Notes — with the
    // notes in D / A / R order and the nurse's signature under them. A section
    // nobody has recorded yet (an Action, or a Response before there is an
    // outcome) is left out rather than printed as an empty heading.
    const fdarSheet = (report) => {
        const table = el('table', 'fdar-sheet');
        const caption = el('caption', 'sr-only', 'Focus charting (F-DAR)');
        table.appendChild(caption);

        const thead = el('thead');
        const headRow = el('tr');
        ['Date / Time', 'Focus', 'Progress Notes'].forEach((heading) => {
            const th = el('th', null, heading);
            th.scope = 'col';
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = el('tbody');
        const row = el('tr');

        const when = el('td', 'fdar-when-cell');
        when.appendChild(el('span', 'fdar-date', report.occurred_label || report.occurred_at || '-'));
        if (report.occurred_time_label) when.appendChild(el('span', 'fdar-time', report.occurred_time_label));
        row.appendChild(when);

        // The nurse's own focus statement leads; the kind of incident sits
        // under it, unless the statement is the kind (nothing else written).
        const focus = el('td', 'fdar-focus-cell');
        focus.appendChild(el('span', 'fdar-focus-main', report.focus_label || report.category_label));
        if (report.focus && report.focus_label !== report.category_label) {
            focus.appendChild(el('span', 'fdar-focus-kind', report.category_label));
        }
        row.appendChild(focus);

        const notes = el('td', 'fdar-notes-cell');
        notes.appendChild(noteLine('D', 'Data', report.description));
        if (report.action_taken) notes.appendChild(noteLine('A', 'Action', report.action_taken));
        if (report.response) notes.appendChild(noteLine('R', 'Response', report.response));

        // Signed, as a note is: the name and the title of who charted it, and
        // when it was filed. Attribution is the server's, not the form's.
        const signer = [report.reported_by, report.reported_by_title].filter(Boolean).join(', ');
        if (signer || report.filed_label) {
            const sig = el('div', 'fdar-signature');
            if (signer) sig.appendChild(el('span', 'fdar-signature-name', '— ' + signer));
            if (report.filed_label) sig.appendChild(el('span', 'fdar-signature-time', 'Filed ' + report.filed_label));
            notes.appendChild(sig);
        }
        row.appendChild(notes);

        tbody.appendChild(row);
        table.appendChild(tbody);

        const wrap = el('div', 'fdar-sheet-scroll');
        wrap.appendChild(table);
        return wrap;
    };

    const renderRow = (report) => {
        const card = el('article', 'incident-card');
        card.dataset.id = report.id;

        // The date and time are on the sheet itself; the head carries what is
        // not part of F-DAR — how serious it was and whether home was told.
        const head = el('div', 'incident-card-head');
        head.append(
            el('span', 'incident-sev incident-sev-' + report.severity, report.severity_label),
        );

        if (report.guardian_notified) {
            head.appendChild(el('span', 'incident-flag', 'Guardian informed'));
        }

        // Withdrawing is for a report filed by mistake, and it belongs to
        // whoever may file. An adviser reading the panel gets no button —
        // and the endpoint refuses them regardless of what is drawn.
        if (canFile) {
            const remove = el('button', 'incident-remove', 'Withdraw');
            remove.type = 'button';
            remove.dataset.remove = report.id;
            head.appendChild(remove);
        }

        card.appendChild(head);

        // The chart itself: the F-DAR sheet.
        card.appendChild(fdarSheet(report));

        // Everything that is not part of the chart.
        const facts = el('div', 'incident-facts');
        const addFact = (label, value) => {
            if (!value) return;
            const row = el('div');
            row.append(el('span', null, label), el('b', null, value));
            facts.appendChild(row);
        };
        addFact('Where:', report.location);
        addFact('Witnesses:', report.witnesses);

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

    const load = async (lrn) => {
        if (lrn !== undefined && lrn !== null) currentLrn = String(lrn || '');
        if (currentLrn === '') return;

        try {
            const response = await fetch(urlFor('index', currentLrn), { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();

            // The server is the authority on who may write, so a panel that
            // rendered a form for the wrong session loses it here.
            if (typeof data.can_file === 'boolean') {
                canFile = data.can_file;
                if (!canFile) {
                    setOpen(false);
                    if (newBtn) newBtn.hidden = true;
                }
            }

            render(Array.isArray(data.reports) ? data.reports : []);
        } catch (_) {
            // Leave whatever is on screen rather than blanking the history.
        }
    };

    if (form) {
        newBtn?.addEventListener('click', () => { showError(''); setOpen(true); });
        cancelBtn?.addEventListener('click', () => { form.reset(); showError(''); setOpen(false); });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            showError('');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const response = await fetch(urlFor('store', currentLrn), {
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
    }

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button || !canFile || currentLrn === '') return;

        if (!window.confirm('Withdraw this incident report? This is recorded in the audit trail.')) return;

        try {
            const response = await fetch(
                urlFor('index', currentLrn) + '/' + button.dataset.remove,
                { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } }
            );
            if (!response.ok) return;
            const data = await response.json();
            render(Array.isArray(data.reports) ? data.reports : []);
        } catch (_) {
            // Nothing to do — the row stays until the next successful read.
        }
    });

    // A page that already knows its learner loads straight away; the nurse's
    // dialog calls load(lrn) when a row is opened.
    if (currentLrn !== '') load(currentLrn);

    return { load, render };
})();
</script>
