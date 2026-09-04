{{--
    Behaviour for the clinic's consultation-photo dialog.

    Captions are typed by staff about a child, so the list is built from DOM
    nodes and never innerHTML.
--}}
<script>
(() => {
    const backdrop = document.getElementById('cphotoBackdrop');
    if (!backdrop) return;

    const list = document.getElementById('cphotoList');
    const sub = document.getElementById('cphotoSub');
    const fileInput = document.getElementById('cphotoFile');
    const captionInput = document.getElementById('cphotoCaption');
    const shareInput = document.getElementById('cphotoShare');
    const uploadBtn = document.getElementById('cphotoUpload');
    const errorBox = document.getElementById('cphotoError');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const base = @json(url('health-records'));
    let consultationId = null;

    const indexUrl = (id) => base + '/consultations/' + encodeURIComponent(id) + '/photos';
    const photoUrl = (id) => base + '/consultation-photos/' + encodeURIComponent(id);

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

    const render = (photos) => {
        list.textContent = '';
        const rows = Array.isArray(photos) ? photos : [];

        if (rows.length === 0) {
            list.appendChild(el('p', 'cphoto-empty', 'No photos on this consultation yet.'));
            return;
        }

        rows.forEach((photo) => {
            const card = el('figure', 'cphoto-card');

            const link = document.createElement('a');
            link.href = photo.url;
            link.target = '_blank';
            link.rel = 'noopener';

            const img = document.createElement('img');
            img.src = photo.url;
            img.alt = photo.caption || 'Consultation photo';
            img.loading = 'lazy';
            link.appendChild(img);
            card.appendChild(link);

            const caption = el('figcaption', 'cphoto-caption');
            if (photo.caption) caption.appendChild(el('div', 'cphoto-caption-text', photo.caption));
            caption.appendChild(el('div', 'cphoto-meta',
                [photo.uploaded_by, photo.taken_label].filter(Boolean).join(' · ')));
            card.appendChild(caption);

            const actions = el('div', 'cphoto-actions');

            // Sharing is its own decision, reversible: a nurse who realises a
            // teacher needs to know should not have to re-upload, and one who
            // shared by mistake must be able to take it back.
            const share = el('label', 'cphoto-share');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = Boolean(photo.shared_with_adviser);
            box.dataset.share = photo.id;
            share.append(box, el('span', null, 'Shared with class adviser'));
            actions.appendChild(share);

            const remove = el('button', 'cphoto-remove', 'Remove');
            remove.type = 'button';
            remove.dataset.remove = photo.id;
            actions.appendChild(remove);

            card.appendChild(actions);
            list.appendChild(card);
        });
    };

    const load = async () => {
        if (consultationId === null) return;
        try {
            const response = await fetch(indexUrl(consultationId), { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            render((await response.json()).photos);
        } catch (_) { /* leave what is on screen */ }
    };

    const open = (id, student) => {
        consultationId = id;
        showError('');
        if (fileInput) fileInput.value = '';
        if (captionInput) captionInput.value = '';
        if (shareInput) shareInput.checked = false;
        if (sub) sub.textContent = student ? 'Photos for ' + student : '';
        list.textContent = '';
        backdrop.hidden = false;
        document.body.classList.add('bmodal-open');
        load();
    };

    const close = () => {
        backdrop.hidden = true;
        consultationId = null;
        document.body.classList.remove('bmodal-open');
    };

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-photos-open]');
        if (opener) {
            open(opener.dataset.photosOpen, opener.dataset.photosStudent || '');
            return;
        }
        if (event.target.closest('[data-photos-close]') || event.target === backdrop) close();
    });

    document.getElementById('cphotoClose')?.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !backdrop.hidden) close(); });

    uploadBtn?.addEventListener('click', async () => {
        if (consultationId === null) return;
        const file = fileInput?.files && fileInput.files[0];
        if (!file) { showError('Choose a photo first.'); return; }

        showError('');
        uploadBtn.disabled = true;

        const body = new FormData();
        body.append('photo', file);
        body.append('caption', captionInput?.value ?? '');
        body.append('shared_with_adviser', shareInput?.checked ? '1' : '0');

        try {
            const response = await fetch(indexUrl(consultationId), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body,
            });

            if (response.status === 422) {
                const data = await response.json();
                const first = Object.values(data.errors || {})[0];
                showError(Array.isArray(first) ? first[0] : 'That file could not be accepted.');
                return;
            }
            if (!response.ok) { showError('The photo could not be uploaded.'); return; }

            render((await response.json()).photos);
            fileInput.value = '';
            captionInput.value = '';
            shareInput.checked = false;
        } catch (_) {
            showError('The photo could not be uploaded.');
        } finally {
            uploadBtn.disabled = false;
        }
    });

    list?.addEventListener('change', async (event) => {
        const box = event.target.closest('[data-share]');
        if (!box) return;

        try {
            const response = await fetch(photoUrl(box.dataset.share) + '/share', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ shared_with_adviser: box.checked }),
            });
            if (!response.ok) { box.checked = !box.checked; return; }
            render((await response.json()).photos);
        } catch (_) {
            box.checked = !box.checked;
        }
    });

    list?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button) return;
        if (!window.confirm('Remove this photo? This is recorded in the audit trail.')) return;

        try {
            const response = await fetch(photoUrl(button.dataset.remove), {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            });
            if (!response.ok) return;
            render((await response.json()).photos);
        } catch (_) { /* the row stays until the next successful read */ }
    });
})();
</script>
