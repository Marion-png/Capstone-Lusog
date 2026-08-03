{{--
    Behaviour for partials/student-documents-panel. Exposes window.StudentDocuments:

      StudentDocuments.init({ onCount })  wire the drop zone once, on page load
      StudentDocuments.load(lrn)          fetch and render that learner's list
      StudentDocuments.render(list, lrn)  render a list the page already has

    The same endpoints serve the class adviser, the school nurse, and clinic
    staff, so both profiles behave identically and each sees what the other
    filed. Role and own-school checks live in StudentMedicalDocumentController.
--}}
<script>
window.StudentDocuments = (() => {
    // {lrn} is filled in per learner: the nurse's profile is a modal that
    // switches learners without reloading the page.
    const URL_TEMPLATES = {
        index: @json(route('student-documents.index', ['lrn' => '__LRN__'])),
        store: @json(route('student-documents.store', ['lrn' => '__LRN__'])),
        pulse: @json(route('student-documents.pulse', ['lrn' => '__LRN__'])),
    };

    // Matches the adviser dashboard's activity poller. The panel asks a no-PII
    // endpoint whether anything changed and only re-reads the list when it did.
    const PULSE_MS = 20000;

    const urlFor = (kind, lrn) => URL_TEMPLATES[kind].replace('__LRN__', encodeURIComponent(lrn));

    // Extension → badge label and colour. Doubles as the client-side format
    // allow-list; the server validates the same set on upload.
    const KINDS = {
        pdf: ['PDF', 'pdf'],
        jpg: ['JPG', 'img'], jpeg: ['JPG', 'img'], png: ['PNG', 'img'],
        doc: ['DOC', 'doc'], docx: ['DOC', 'doc'],
        xls: ['XLS', 'xls'], xlsx: ['XLS', 'xls'],
    };

    const MAX_BYTES = 10 * 1024 * 1024;

    const ICONS = {
        size: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        date: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        person: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        condition: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        view: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        remove: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
    };

    let currentLrn = '';
    let currentStamp = null;
    let onCount = () => {};

    const el = (id) => document.getElementById(id);
    const extensionOf = (name) => String(name || '').split('.').pop().toLowerCase();
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const formatBytes = (bytes) => {
        const size = Number(bytes);
        if (!Number.isFinite(size) || size <= 0) {
            return null;
        }
        if (size < 1024) {
            return `${size} B`;
        }
        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(0)} KB`;
        }
        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };

    // Icon markup is a fixed string, never document data — file names and
    // uploader names are written with textContent below.
    const icon = (paths) => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.innerHTML = paths;
        return svg;
    };

    const feedback = (message, isError = false) => {
        const node = el('sdFeedback');
        if (!node) {
            return;
        }
        node.textContent = message || '';
        node.classList.toggle('is-error', Boolean(message) && isError);
        node.classList.toggle('is-shown', Boolean(message));
    };

    const setCounts = (count) => {
        const label = `${count} ${count === 1 ? 'file' : 'files'}`;
        ['sdCount', 'sdListCount'].forEach((id) => {
            const node = el(id);
            if (node) {
                node.textContent = label;
            }
        });
        onCount(count);
    };

    const setBusy = (busy) => {
        el('sdDrop')?.classList.toggle('is-busy', busy);
        const browse = el('sdBrowse');
        if (browse) {
            browse.disabled = busy;
        }
    };

    /**
     * Reads a JSON reply, turning the login redirect Laravel issues on an
     * expired session into a message the user can act on instead of a silent
     * "unexpected token <" failure.
     */
    const readJson = async (response) => {
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) {
            throw new Error('Your session expired. Please reload the page and try again.');
        }

        const payload = await response.json();
        if (!response.ok) {
            throw new Error(payload.message || 'Something went wrong. Please try again.');
        }

        return payload;
    };

    const buildRow = (doc) => {
        const [badge, kind] = KINDS[extensionOf(doc.file_name)] || ['FILE', 'other'];

        const row = document.createElement('div');
        row.className = 'doc-row';

        const badgeNode = document.createElement('span');
        badgeNode.className = `doc-icon ${kind}`;
        badgeNode.textContent = badge;
        row.appendChild(badgeNode);

        const body = document.createElement('div');
        body.className = 'doc-body';

        const name = document.createElement('div');
        name.className = 'doc-name';
        name.textContent = doc.file_name || 'Untitled document';
        body.appendChild(name);

        const meta = document.createElement('div');
        meta.className = 'doc-meta';
        [
            [ICONS.size, formatBytes(doc.file_size)],
            [ICONS.date, doc.uploaded_at],
            [ICONS.person, doc.uploaded_by],
            [ICONS.condition, doc.condition_name],
        ].forEach(([paths, value]) => {
            if (!value) {
                return;
            }
            const span = document.createElement('span');
            span.appendChild(icon(paths));
            span.appendChild(document.createTextNode(String(value)));
            meta.appendChild(span);
        });

        // Which desk filed it — the adviser and the nurse share this list.
        if (doc.uploaded_by_role) {
            const role = document.createElement('span');
            role.className = 'doc-role';
            role.textContent = doc.uploaded_by_role;
            meta.appendChild(role);
        }

        body.appendChild(meta);
        row.appendChild(body);

        const actions = document.createElement('div');
        actions.className = 'doc-actions';

        const view = document.createElement('a');
        view.className = 'doc-action view';
        view.href = doc.view_url;
        view.target = '_blank';
        view.rel = 'noopener';
        view.title = 'View';
        view.setAttribute('aria-label', 'View document');
        view.appendChild(icon(ICONS.view));
        actions.appendChild(view);

        const download = document.createElement('a');
        download.className = 'doc-action download';
        download.href = doc.download_url;
        download.title = 'Download';
        download.setAttribute('aria-label', 'Download document');
        download.appendChild(icon(ICONS.download));
        actions.appendChild(download);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'doc-action delete';
        remove.title = 'Delete';
        remove.setAttribute('aria-label', 'Delete document');
        remove.appendChild(icon(ICONS.remove));
        remove.addEventListener('click', () => destroy(doc));
        actions.appendChild(remove);

        row.appendChild(actions);

        return row;
    };

    const render = (documents, lrn = null, stamp = null) => {
        if (lrn !== null) {
            currentLrn = String(lrn || '');
        }

        if (stamp !== null) {
            currentStamp = stamp;
        }

        el('sdDrop')?.classList.toggle('is-disabled', currentLrn === '');

        const host = el('sdList');
        if (!host) {
            return;
        }

        host.textContent = '';
        const list = Array.isArray(documents) ? documents : [];
        setCounts(list.length);

        if (!list.length) {
            const empty = document.createElement('p');
            empty.className = 'doc-empty';
            empty.textContent = 'No medical documents uploaded yet.';
            host.appendChild(empty);
            return;
        }

        list.forEach((doc) => host.appendChild(buildRow(doc)));
    };

    const load = async (lrn) => {
        currentLrn = String(lrn || '');
        currentStamp = null;
        feedback('');

        if (currentLrn === '') {
            render([], '');
            return;
        }

        try {
            const payload = await readJson(await fetch(urlFor('index', currentLrn), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            }));
            render(payload.documents, currentLrn, payload.stamp ?? null);
        } catch (error) {
            // Rendering empty rather than keeping what was on screen: on the
            // nurse's profile the panel switches learners in place, and showing
            // one learner's documents under another's name is the worse failure.
            render([], currentLrn);
            feedback(error.message, true);
        }
    };

    const upload = async (file) => {
        if (!file || currentLrn === '') {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(KINDS, extensionOf(file.name))) {
            feedback('Only PDF, JPG, PNG, DOC, and XLS files may be uploaded.', true);
            return;
        }

        if (file.size > MAX_BYTES) {
            feedback('The document may not be larger than 10MB.', true);
            return;
        }

        const body = new FormData();
        body.append('document', file);

        setBusy(true);
        feedback(`Uploading "${file.name}"…`);

        try {
            const payload = await readJson(await fetch(urlFor('store', currentLrn), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            }));
            render(payload.documents, currentLrn, payload.stamp ?? null);
            feedback(`"${file.name}" uploaded.`);
        } catch (error) {
            feedback(error.message, true);
        } finally {
            setBusy(false);
        }
    };

    const destroy = async (doc) => {
        if (!window.confirm(`Delete "${doc.file_name}"? This cannot be undone.`)) {
            return;
        }

        try {
            const payload = await readJson(await fetch(doc.delete_url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                credentials: 'same-origin',
            }));
            render(payload.documents, currentLrn, payload.stamp ?? null);
            feedback(`"${doc.file_name}" deleted.`);
        } catch (error) {
            feedback(error.message, true);
        }
    };

    /**
     * Is the panel actually on screen? The adviser's sits behind a tab and the
     * nurse's inside a modal, and neither should be polled while hidden.
     * offsetParent is null exactly when an ancestor is display:none.
     */
    const panelVisible = () => {
        const list = el('sdList');

        return Boolean(list && list.offsetParent !== null);
    };

    /**
     * Ask whether anything changed for this learner — a document the nurse or
     * the adviser filed from their own screen — and re-read the list if so.
     */
    const pulse = async () => {
        if (document.hidden || currentLrn === '' || !panelVisible()) {
            return;
        }

        try {
            const response = await fetch(urlFor('pulse', currentLrn), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }

            const { stamp } = await response.json();
            if (!stamp) {
                return;
            }

            if (currentStamp === null) {
                currentStamp = stamp;

                return;
            }

            if (stamp !== currentStamp) {
                await load(currentLrn);
            }
        } catch (error) {
            // Ignored — the next pulse retries.
        }
    };

    const init = (options = {}) => {
        onCount = typeof options.onCount === 'function' ? options.onCount : () => {};

        const drop = el('sdDrop');
        const input = el('sdInput');
        const browse = el('sdBrowse');

        if (!drop || !input) {
            return;
        }

        const openPicker = () => input.click();

        browse?.addEventListener('click', (event) => {
            event.stopPropagation();
            openPicker();
        });

        drop.addEventListener('click', openPicker);
        drop.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openPicker();
            }
        });

        input.addEventListener('change', () => {
            const file = input.files?.[0];
            // Reset first, so re-picking the same file still fires `change`.
            input.value = '';
            upload(file);
        });

        ['dragenter', 'dragover'].forEach((type) => {
            drop.addEventListener(type, (event) => {
                event.preventDefault();
                drop.classList.add('is-dragging');
            });
        });

        ['dragleave', 'drop'].forEach((type) => {
            drop.addEventListener(type, (event) => {
                event.preventDefault();
                drop.classList.remove('is-dragging');
            });
        });

        drop.addEventListener('drop', (event) => upload(event.dataTransfer?.files?.[0]));

        // Keeps an open panel current with what the other desks file.
        setInterval(pulse, PULSE_MS);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                pulse();
            }
        });
    };

    return { init, load, render, pulse };
})();
</script>
