{{--
    Behaviour for partials/student-incidents-panel. Exposes window.StudentIncidents:

      StudentIncidents.load(lrn)     fetch and render that learner's reports

    Besides filing, editing and withdrawing, it prints a report — or the form
    blank — on the school's Incident Report form through a hidden frame, so the
    profile stays open, and runs the AI grammar and terms check: one request
    for every written field, each suggestion shown against what was written
    with the changed words marked, to accept or dismiss. Nothing the check
    returns is saved until the nurse saves the form.

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
    const formTitle = document.getElementById('incidentFormTitle');
    const countLabel = document.getElementById('vpIncidentsCount');
    const tabBadge = document.getElementById('vpIncidentsTabBadge');
    const actions = document.getElementById('incidentActions');
    const blankBtn = document.getElementById('incidentBlankBtn');
    const proofBtn = document.getElementById('incidentProofBtn');
    const proofBox = document.getElementById('incidentProof');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // {lrn} is filled in per learner, because the nurse's profile is a modal
    // that serves whichever row was clicked.
    const URL_TEMPLATES = {
        index: @json(route('student-incidents.index', ['lrn' => '__LRN__'])),
        store: @json(route('student-incidents.store', ['lrn' => '__LRN__'])),
        proofread: @json(route('student-incidents.proofread', ['lrn' => '__LRN__'])),
        print: @json(route('student-incidents.print', ['lrn' => '__LRN__', 'id' => 987654321])),
        blank: @json(route('student-incidents.blank', ['lrn' => '__LRN__'])),
    };

    const urlFor = (kind, lrn, id) => URL_TEMPLATES[kind]
        .replace('__LRN__', encodeURIComponent(lrn))
        .replace('987654321', encodeURIComponent(String(id ?? '')));

    // The text fields the AI check reads, keyed as the endpoint names them,
    // with the label each suggestion is shown under. A field the page did not
    // draw (a database not yet migrated) is passed over.
    const PROOF_FIELDS = {
        focus: ['incidentFocus', 'Focus'],
        description: ['incidentDescription', 'Data'],
        exam_body_site: ['incidentExam_body_site', 'Body part affected'],
        exam_findings: ['incidentExam_findings', 'Examination findings'],
        action_taken: ['incidentAction', 'Action'],
        response: ['incidentResponse', 'Response'],
    };

    // The page may name its learner at render time (the adviser's profile) or
    // hand one over on every open (the nurse's dialog).
    let currentLrn = @json((string) ($lrn ?? ''));

    // Whether this session may file. The server decides and says so on every
    // list; until it has answered, the panel is whatever the page rendered.
    let canFile = !!form;

    // Editing and withdrawing are each narrower than filing, so each is its own
    // answer, read the same way.
    let canEdit = list.dataset.canEdit === '1';
    let canWithdraw = list.dataset.canWithdraw === '1';

    // The reports on screen by id, for the form to be filled from, and which
    // one the form is editing. Null means it is filing a new one.
    const shown = new Map();
    let editingId = null;

    // While the form is open the history is put away, so the report being
    // written is never confused with one already filed.
    const historyBox = document.getElementById('incidentHistory');

    const setOpen = (open) => {
        if (!form) return;
        form.hidden = !open;
        if (actions) actions.hidden = open;
        else if (newBtn) newBtn.hidden = open;
        if (historyBox) historyBox.hidden = open;
        if (open) document.getElementById('incidentDate')?.focus();
    };

    const showError = (message) => {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.hidden = message === '';
    };

    const setEditing = (id) => {
        editingId = id;
        if (formTitle) formTitle.hidden = id === null;
        if (submitBtn) submitBtn.textContent = id === null ? 'Save Report' : 'Save Changes';
    };

    // Back to a closed, empty form that files a new report.
    const closeForm = () => {
        if (!form) return;
        form.reset();
        showError('');
        clearProof();
        setEditing(null);
        setOpen(false);
    };

    // The form is opened on one report's own values. A field the page did not
    // draw (a database not yet migrated) is passed over.
    const startEdit = (report) => {
        if (!form) return;
        form.reset();
        showError('');

        const fields = {
            incidentDate: report.occurred_at,
            incidentTime: report.occurred_time,
            incidentCategory: report.category,
            incidentFocus: report.focus,
            incidentDescription: report.description,
            incidentAction: report.action_taken,
            incidentResponse: report.response,
            incidentSeverity: report.severity,
            incidentLocation: report.location,
            incidentWitnesses: report.witnesses,
        };
        Object.entries(fields).forEach(([id, value]) => {
            const field = document.getElementById(id);
            if (field) field.value = value ?? '';
        });

        const notified = document.getElementById('incidentNotified');
        if (notified) notified.checked = !!report.guardian_notified;

        // The physical examination, one field per stored reading.
        Object.entries(report.physical_exam || {}).forEach(([key, value]) => {
            const field = document.getElementById('incidentExam_' + key);
            if (field) field.value = value ?? '';
        });

        clearProof();

        setEditing(report.id);
        setOpen(true);
        form.scrollIntoView({ block: 'start' });
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

    // The physical examination under the Data line: the vital signs on one
    // row of chips, then each other answer under its own label. Only what was
    // recorded; nothing at all when no examination was charted.
    const examBlock = (summary) => {
        const vitals = Array.isArray(summary?.vitals) ? summary.vitals : [];
        const lines = Array.isArray(summary?.lines) ? summary.lines : [];
        if (vitals.length === 0 && lines.length === 0) return null;

        const block = el('div', 'fdar-exam-read');
        block.appendChild(el('span', 'fdar-exam-read-head', 'Physical Examination'));

        if (vitals.length) {
            const row = el('div', 'fdar-exam-vitals');
            vitals.forEach((vital) => {
                const chip = el('span', 'fdar-exam-chip');
                chip.title = vital.label;
                chip.append(el('b', null, vital.short), document.createTextNode(' ' + vital.value));
                row.appendChild(chip);
            });
            block.appendChild(row);
        }

        lines.forEach((line) => {
            const row = el('div', 'fdar-exam-line');
            row.append(el('span', null, line.label + ':'), el('p', null, line.value));
            block.appendChild(row);
        });

        return block;
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
        const exam = examBlock(report.exam_summary);
        if (exam) notes.appendChild(exam);
        if (report.action_taken) notes.appendChild(noteLine('A', 'Action', report.action_taken));
        if (report.response) notes.appendChild(noteLine('R', 'Response', report.response));

        // Signed, as a note is: the name and the title of who charted it, and
        // when it was filed. Attribution is the server's, not the form's.
        const signer = [report.reported_by, report.reported_by_title].filter(Boolean).join(', ');
        if (signer || report.filed_label) {
            const sig = el('div', 'fdar-signature');
            if (signer) sig.appendChild(el('span', 'fdar-signature-name', '— ' + signer));
            if (report.filed_label) sig.appendChild(el('span', 'fdar-signature-time', 'Filed ' + report.filed_label));
            // The signature stands through an edit, so whoever changed the note
            // is named beside it rather than leaving their words under it.
            if (report.edited_label) {
                const editor = [report.edited_by, report.edited_by_title].filter(Boolean).join(', ');
                sig.appendChild(el('span', 'fdar-signature-edited', 'Edited ' + report.edited_label + (editor ? ' by ' + editor : '')));
            }
            notes.appendChild(sig);
        }
        row.appendChild(notes);

        tbody.appendChild(row);
        table.appendChild(tbody);

        const wrap = el('div', 'fdar-sheet-scroll');
        wrap.appendChild(table);
        return wrap;
    };

    const printIcon = () => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('aria-hidden', 'true');
        [['path', { d: 'M6 9V3h12v6' }], ['rect', { x: '6', y: '14', width: '12', height: '7' }], ['path', { d: 'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2' }]]
            .forEach(([tag, attrs]) => {
                const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
                Object.entries(attrs).forEach(([name, value]) => node.setAttribute(name, value));
                svg.appendChild(node);
            });
        return svg;
    };

    // The form is printed from a hidden frame, so the profile — and the
    // learner on screen — stays where it is. Whatever comes back that is not
    // the form (a session that ran out, a report since withdrawn) is shown in
    // a tab of its own instead of being printed.
    const printInFrame = (url, button) => {
        document.getElementById('incidentPrintFrame')?.remove();

        const frame = document.createElement('iframe');
        frame.id = 'incidentPrintFrame';
        frame.title = 'Incident report print';
        frame.tabIndex = -1;
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none';

        if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }

        frame.addEventListener('load', () => {
            if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }

            let doc = null;
            try { doc = frame.contentDocument; } catch (_) { doc = null; }

            if (!doc || !doc.body || !doc.body.hasAttribute('data-incident-sheet')) {
                frame.remove();
                window.open(url, '_blank', 'noopener');
                return;
            }

            frame.contentWindow.focus();
            frame.contentWindow.print();
        }, { once: true });

        frame.src = url;
        document.body.appendChild(frame);
    };

    // ── AI grammar and terms check ──────────────────────────────────────────

    // Words, runs of space and single marks, so a diff marks whole words.
    const tokens = (text) => String(text).match(/\s+|[\p{L}\p{N}'’\-]+|[^\s\p{L}\p{N}]/gu) || [];

    // What the suggestion keeps, removes and adds, word by word (a plain
    // longest-common-subsequence over two short notes).
    const diff = (before, after) => {
        const a = tokens(before);
        const b = tokens(after);
        const table = Array.from({ length: a.length + 1 }, () => new Uint16Array(b.length + 1));
        for (let i = a.length - 1; i >= 0; i--) {
            for (let j = b.length - 1; j >= 0; j--) {
                table[i][j] = a[i] === b[j] ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
            }
        }

        const parts = [];
        const push = (kind, text) => {
            const last = parts[parts.length - 1];
            if (last && last.kind === kind) last.text += text;
            else parts.push({ kind, text });
        };

        let i = 0;
        let j = 0;
        while (i < a.length && j < b.length) {
            if (a[i] === b[j]) { push('same', a[i]); i++; j++; }
            else if (table[i + 1][j] >= table[i][j + 1]) { push('del', a[i]); i++; }
            else { push('ins', b[j]); j++; }
        }
        while (i < a.length) push('del', a[i++]);
        while (j < b.length) push('ins', b[j++]);

        return parts;
    };

    // The suggestions on screen, by field: what was written and what is proposed.
    const pending = new Map();
    let proofRun = 0;

    const clearProof = () => {
        proofRun++;
        pending.clear();
        if (proofBox) {
            proofBox.textContent = '';
            proofBox.hidden = true;
        }
    };

    const fieldOf = (key) => document.getElementById(PROOF_FIELDS[key]?.[0] || '');

    const renderProof = (emptyMessage) => {
        if (!proofBox) return;
        proofBox.textContent = '';

        const head = el('div', 'incident-proof-head');
        head.appendChild(el('span', 'incident-proof-title', pending.size === 0
            ? emptyMessage
            : 'Suggested corrections (' + pending.size + ')'));

        if (pending.size > 1) {
            const all = el('button', 'incident-proof-btn is-primary', 'Accept All');
            all.type = 'button';
            all.dataset.proofAll = '1';
            head.appendChild(all);
        }

        const close = el('button', 'incident-proof-btn', pending.size === 0 ? 'Close' : 'Dismiss All');
        close.type = 'button';
        close.dataset.proofClose = '1';
        head.appendChild(close);
        proofBox.appendChild(head);

        pending.forEach((suggestion, key) => {
            const item = el('div', 'incident-proof-item');
            item.dataset.key = key;

            const top = el('div', 'incident-proof-item-head');
            top.appendChild(el('span', 'incident-proof-field', PROOF_FIELDS[key]?.[1] || key));

            const accept = el('button', 'incident-proof-btn is-primary', 'Accept');
            accept.type = 'button';
            accept.dataset.proofAccept = key;
            const keep = el('button', 'incident-proof-btn', 'Keep Mine');
            keep.type = 'button';
            keep.dataset.proofKeep = key;
            top.append(accept, keep);
            item.appendChild(top);

            const text = el('p', 'incident-proof-diff');
            diff(suggestion.original, suggestion.suggested).forEach((part) => {
                if (part.kind === 'same') text.appendChild(document.createTextNode(part.text));
                else text.appendChild(el(part.kind, null, part.text));
            });
            item.appendChild(text);

            proofBox.appendChild(item);
        });

        proofBox.hidden = false;
    };

    const accept = (key) => {
        const suggestion = pending.get(key);
        const field = fieldOf(key);
        pending.delete(key);
        if (suggestion && field && field.value.trim() === suggestion.original) field.value = suggestion.suggested;
    };

    const afterDecision = () => {
        if (pending.size === 0) clearProof();
        else renderProof('');
    };

    const runProof = async () => {
        if (!proofBtn || currentLrn === '') return;
        showError('');

        const fields = {};
        Object.entries(PROOF_FIELDS).forEach(([key, [id]]) => {
            const value = document.getElementById(id)?.value.trim() || '';
            if (value !== '') fields[key] = value;
        });

        if (Object.keys(fields).length === 0) {
            showError('Write the report first, then check it.');
            return;
        }

        clearProof();
        const run = proofRun;
        const label = proofBtn.querySelector('.incident-ai-label');
        const idle = label ? label.textContent : '';
        proofBtn.disabled = true;
        proofBtn.setAttribute('aria-busy', 'true');
        if (label) label.textContent = 'Checking…';

        try {
            const response = await fetch(urlFor('proofread', currentLrn), {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ fields, witnesses: document.getElementById('incidentWitnesses')?.value || '' }),
            });
            const data = await response.json().catch(() => ({}));

            // The form moved on (closed, or another learner) while it ran.
            if (run !== proofRun) return;

            if (!response.ok) {
                showError(data.message || 'The check could not be run. Nothing on the form was changed.');
                return;
            }

            Object.entries(data.suggestions || {}).forEach(([key, suggestion]) => {
                // Only a suggestion for what is still in the field.
                const field = fieldOf(key);
                if (field && suggestion && field.value.trim() === suggestion.original) pending.set(key, suggestion);
            });

            renderProof('No corrections suggested.');
        } catch (_) {
            if (run === proofRun) showError('The check could not be run. Nothing on the form was changed.');
        } finally {
            proofBtn.disabled = false;
            proofBtn.removeAttribute('aria-busy');
            if (label) label.textContent = idle;
        }
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

        // Every reader may print it: the adviser signs the form too.
        const print = el('button', 'incident-print');
        print.type = 'button';
        print.dataset.print = report.id;
        print.append(printIcon(), document.createTextNode('Print'));
        const tools = el('span', 'incident-card-tools');
        tools.appendChild(print);
        head.appendChild(tools);

        // Correcting a report is the nurse's and the clinic teacher's. Anyone
        // else gets no button, and the endpoint refuses them regardless.
        if (canEdit && form) {
            const edit = el('button', 'incident-edit', 'Edit');
            edit.type = 'button';
            edit.dataset.edit = report.id;
            tools.appendChild(edit);
        }

        // Withdrawing is for a report filed by mistake, and it is the
        // nurse's. An adviser or a clinic teacher reading the panel gets no
        // button — and the endpoint refuses them regardless of what is drawn.
        if (canWithdraw) {
            const remove = el('button', 'incident-remove', 'Withdraw');
            remove.type = 'button';
            remove.dataset.remove = report.id;
            tools.appendChild(remove);
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

        shown.clear();
        reports.forEach((report) => shown.set(String(report.id), report));

        // The report being edited was withdrawn: there is nothing left to save to.
        if (editingId !== null && !shown.has(String(editingId))) closeForm();

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
        if (lrn !== undefined && lrn !== null) {
            const next = String(lrn || '');
            // The nurse's dialog serves whichever row was opened, so a form left
            // open on one child must not be saved against the next.
            if (next !== currentLrn) closeForm();
            currentLrn = next;
        }
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
                    if (actions) actions.hidden = true;
                    if (newBtn) newBtn.hidden = true;
                }
            }

            if (typeof data.can_edit === 'boolean') {
                canEdit = data.can_edit;
            }

            if (typeof data.can_withdraw === 'boolean') {
                canWithdraw = data.can_withdraw;
            }

            render(Array.isArray(data.reports) ? data.reports : []);
        } catch (_) {
            // Leave whatever is on screen rather than blanking the history.
        }
    };

    if (form) {
        newBtn?.addEventListener('click', () => { showError(''); clearProof(); setEditing(null); setOpen(true); });
        cancelBtn?.addEventListener('click', closeForm);

        blankBtn?.addEventListener('click', () => {
            if (currentLrn !== '') printInFrame(urlFor('blank', currentLrn), blankBtn);
        });

        proofBtn?.addEventListener('click', runProof);

        proofBox?.addEventListener('click', (event) => {
            const button = event.target.closest('button');
            if (!button) return;

            if (button.dataset.proofAccept) { accept(button.dataset.proofAccept); afterDecision(); return; }
            if (button.dataset.proofKeep) { pending.delete(button.dataset.proofKeep); afterDecision(); return; }
            if (button.dataset.proofAll) { Array.from(pending.keys()).forEach(accept); afterDecision(); return; }
            if (button.dataset.proofClose) clearProof();
        });

        // A field typed over after the check no longer matches its suggestion,
        // so the suggestion goes rather than overwrite the new words.
        form.addEventListener('input', (event) => {
            const key = Object.keys(PROOF_FIELDS).find((k) => PROOF_FIELDS[k][0] === event.target.id);
            if (key && pending.has(key)) { pending.delete(key); afterDecision(); }
        });

        // Leaving the Incident Reports tab, or coming back to it, returns the
        // panel to where it starts: the form closed, the buttons and the
        // history shown. A form left open on another tab used to greet the
        // nurse on return as though they were still filing or editing. Read in
        // the capture phase, before the page's own tab handler has moved the
        // selection, so "coming back" can be told from a second click on the
        // tab already open — which leaves the form alone.
        document.addEventListener('click', (event) => {
            const tab = event.target.closest('.sp-tab');
            if (!tab || form.hidden) return;

            const panel = document.getElementById(tab.dataset.panel || '');
            const isThisTab = !!panel && panel.contains(list);

            if (!isThisTab || tab.getAttribute('aria-selected') !== 'true') closeForm();
        }, true);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            showError('');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const target = editingId === null
                    ? urlFor('store', currentLrn)
                    : urlFor('index', currentLrn) + '/' + editingId;

                const response = await fetch(target, {
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
                closeForm();
                render(Array.isArray(data.reports) ? data.reports : []);
            } catch (_) {
                showError('The report could not be saved. Please try again.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    list.addEventListener('click', async (event) => {
        const printButton = event.target.closest('[data-print]');
        if (printButton) {
            if (currentLrn !== '') printInFrame(urlFor('print', currentLrn, printButton.dataset.print), printButton);
            return;
        }

        const editButton = event.target.closest('[data-edit]');
        if (editButton) {
            const report = shown.get(editButton.dataset.edit);
            if (report && canEdit) startEdit(report);
            return;
        }

        const button = event.target.closest('[data-remove]');
        if (!button || !canWithdraw || currentLrn === '') return;

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
