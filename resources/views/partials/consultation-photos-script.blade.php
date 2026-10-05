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
    const addPanel = document.getElementById('cphotoAdd');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // The photo, on the stage.
    const stageImg = document.getElementById('cphotoStageImg');
    const stageLoading = document.getElementById('cphotoStageLoading');
    const stageEmpty = document.getElementById('cphotoStageEmpty');
    const stageError = document.getElementById('cphotoStageError');
    const stageErrorTitle = document.getElementById('cphotoStageErrorTitle');
    const stageErrorText = document.getElementById('cphotoStageErrorText');
    const retryBtn = document.getElementById('cphotoRetry');
    const downloadLink = document.getElementById('cphotoDownload');
    const prevBtn = document.getElementById('cphotoPrev');
    const nextBtn = document.getElementById('cphotoNext');
    const stageCount = document.getElementById('cphotoStageCount');

    // Beside it: the comment on that photo, and every photo with its own.
    const detail = document.getElementById('cphotoDetail');
    const comment = document.getElementById('cphotoStageCaption');
    const detailMeta = document.getElementById('cphotoDetailMeta');
    const detailShare = document.getElementById('cphotoDetailShare');
    const detailRemove = document.getElementById('cphotoDetailRemove');
    const openFull = document.getElementById('cphotoOpenFull');
    const allPanel = document.getElementById('cphotoAll');
    const allCount = document.getElementById('cphotoAllCount');

    // Writing or changing the comment on the photo on screen.
    const editBtn = document.getElementById('cphotoEditComment');
    const editor = document.getElementById('cphotoEditor');
    const editText = document.getElementById('cphotoEditText');
    const editError = document.getElementById('cphotoEditError');
    const editSave = document.getElementById('cphotoEditSave');
    const editCancel = document.getElementById('cphotoEditCancel');
    const editCount = document.getElementById('cphotoEditCount');

    // The visit's own Notes / comments, from New Consultation.
    const visitSection = document.getElementById('cphotoVisit');
    const visitNotes = document.getElementById('cphotoVisitNotes');
    const visitMore = document.getElementById('cphotoVisitMore');
    const visitShared = document.getElementById('cphotoVisitShared');

    const base = @json(url('health-records'));
    let consultationId = null;

    // One reading of the visit's photos, newest first (the server's order),
    // and which of them is on the stage. Everything on screen is drawn from
    // these two, so the stage, the comment, the list and the row's count
    // agree.
    let photos = [];
    let currentId = null;
    // Bumped on every image load, so a slow earlier load cannot paint over
    // the photo chosen after it.
    let loadSeq = 0;
    // What Try again re-runs: the list, or the image on the stage.
    let retry = null;
    // The photo whose comment is being written, while the editor is open.
    let editingId = null;

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

    const metaOf = (photo) => [photo.uploaded_by, photo.taken_label].filter(Boolean).join(' · ');

    // ── The stage ─────────────────────────────────────────────────────

    // Exactly one of loading / empty / error / the image is visible at once.
    const stageState = (state) => {
        if (stageLoading) stageLoading.hidden = state !== 'loading';
        if (stageEmpty) stageEmpty.hidden = state !== 'empty';
        if (stageError) stageError.hidden = state !== 'error';
        if (stageImg) stageImg.hidden = state !== 'image';
    };

    const stageFailed = (title, text, download) => {
        if (stageErrorTitle) stageErrorTitle.textContent = title;
        if (stageErrorText) stageErrorText.textContent = text;
        if (downloadLink) {
            downloadLink.hidden = !download;
            downloadLink.href = download || '#';
        }
        stageState('error');
    };

    const currentIndex = () => photos.findIndex((photo) => String(photo.id) === String(currentId));

    // ── The comment editor ────────────────────────────────────────────

    const photoById = (id) => photos.find((photo) => String(photo.id) === String(id));

    const countChars = () => {
        if (editCount && editText) editCount.textContent = editText.value.length + ' / ' + (editText.maxLength > 0 ? editText.maxLength : 500);
    };

    const showEditError = (message) => {
        if (!editError) return;
        editError.textContent = message;
        editError.hidden = message === '';
    };

    const stopEdit = () => {
        editingId = null;
        if (editor) editor.hidden = true;
        if (comment) comment.hidden = false;
        if (editBtn) editBtn.hidden = false;
        showEditError('');
    };

    const startEdit = () => {
        const photo = photoById(currentId);
        if (!photo || !editor || !editText) return;
        editingId = photo.id;
        editText.value = photo.caption || '';
        countChars();
        showEditError('');
        editor.hidden = false;
        if (comment) comment.hidden = true;
        if (editBtn) editBtn.hidden = true;
        editText.focus();
    };

    // Typing that was not saved is somebody's work; it is never thrown away
    // without asking.
    const editIsDirty = () => {
        if (editingId === null || !editText) return false;
        return editText.value.trim() !== String(photoById(editingId)?.caption || '').trim();
    };

    const leaveEdit = () => {
        if (editIsDirty() && !window.confirm('Discard the comment you were writing?')) return false;
        stopEdit();
        return true;
    };

    // The comment and the actions belong to the photo on the stage.
    const showDetail = (photo) => {
        if (detail) detail.hidden = !photo;
        if (!photo) return;

        // An editor open on another photo has nothing to say about this one.
        if (editingId !== null && String(editingId) !== String(photo.id)) stopEdit();

        if (comment) {
            comment.textContent = photo.caption || 'No comment was added to this photo.';
            comment.classList.toggle('is-uncommented', !photo.caption);
        }
        if (editBtn) editBtn.textContent = photo.caption ? 'Edit comment' : 'Add comment';
        if (detailMeta) detailMeta.textContent = metaOf(photo);
        if (detailShare) detailShare.checked = Boolean(photo.shared_with_adviser);
        if (openFull) openFull.href = photo.url;
    };

    const showPhoto = (bustCache = false) => {
        const index = currentIndex();
        const photo = photos[index];

        if (prevBtn) prevBtn.hidden = photos.length < 2;
        if (nextBtn) nextBtn.hidden = photos.length < 2;
        if (stageCount) {
            stageCount.hidden = photos.length < 2;
            stageCount.textContent = photos.length > 1 ? (index + 1) + ' of ' + photos.length : '';
        }
        list?.querySelectorAll('[data-select]').forEach((item) => {
            const active = item.dataset.select === String(currentId);
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-current', active ? 'true' : 'false');
        });

        showDetail(photo);

        if (!photo) {
            if (stageImg) stageImg.removeAttribute('src');
            stageState('empty');
            return;
        }
        if (!stageImg) return;

        // Already on the stage and drawn (a re-render after sharing, say):
        // leave it rather than flashing the loading state.
        if (!bustCache && stageImg.getAttribute('src') === photo.url && stageImg.complete && stageImg.naturalWidth > 0) {
            stageState('image');
            return;
        }

        stageState('loading');
        retry = () => showPhoto(true);

        const seq = ++loadSeq;
        stageImg.onload = () => {
            if (seq === loadSeq) stageState('image');
        };
        stageImg.onerror = () => {
            if (seq !== loadSeq) return;
            // Only Safari draws HEIC. The file is fine; this browser is not,
            // so the way forward is the file itself rather than a retry.
            const heic = /\.hei[cf]$/i.test(photo.file_name || '');
            stageFailed(
                heic ? 'This browser cannot display HEIC photos.' : 'This photo could not be shown.',
                heic ? 'Download it to view it on this device.' : 'Check your connection and try again.',
                photo.url,
            );
        };
        stageImg.alt = photo.caption || 'Consultation photo';
        // A retry asks again rather than reusing a failed answer.
        stageImg.src = bustCache ? photo.url + '?retry=' + Date.now() : photo.url;
    };

    const select = (id) => {
        if (String(id) !== String(currentId) && !leaveEdit()) return;
        currentId = id;
        showPhoto();
    };

    const step = (by) => {
        if (photos.length < 2) return;
        const index = currentIndex();
        select(photos[(index + by + photos.length) % photos.length].id);
    };

    // ── The visit's notes ─────────────────────────────────────────────

    // A long note is folded to a few lines, so the photo's own comment is
    // still in view; "Show more" unfolds it.
    const showVisit = (visit) => {
        if (!visitSection) return;
        if (!visit) { visitSection.hidden = true; return; }

        const notes = String(visit.notes || '').trim();
        const long = notes.length > 280 || notes.split('\n').length > 5;

        if (visitNotes) {
            visitNotes.textContent = notes || 'No notes or comments were written for this visit.';
            visitNotes.classList.toggle('is-uncommented', notes === '');
            visitNotes.classList.toggle('is-clamped', long);
        }
        if (visitMore) {
            visitMore.hidden = !long;
            visitMore.textContent = 'Show more';
            visitMore.setAttribute('aria-expanded', 'false');
        }
        if (visitShared) visitShared.hidden = !(notes && visit.notes_shared_with_adviser);
        visitSection.hidden = false;
    };

    // ── The list ──────────────────────────────────────────────────────

    // The row that opened the dialog says how many photos the visit has, so
    // an upload or a removal here moves that number without a reload.
    const syncCount = () => {
        if (consultationId === null) return;
        document.querySelectorAll('[data-photos-open]').forEach((opener) => {
            if (opener.dataset.photosOpen !== String(consultationId)) return;
            opener.querySelectorAll('[data-photos-count]').forEach((count) => { count.textContent = String(photos.length); });
        });
    };

    const render = (rows, preferId = null) => {
        photos = Array.isArray(rows) ? rows : [];
        list.textContent = '';

        // The photo asked for, else the one already on the stage if it is
        // still there, else the newest.
        const has = (id) => id !== null && photos.some((photo) => String(photo.id) === String(id));
        currentId = has(preferId) ? preferId : (has(currentId) ? currentId : (photos[0]?.id ?? null));

        // One photo needs no list beside it: its comment is already shown.
        if (allPanel) allPanel.hidden = photos.length < 2;
        if (allCount) allCount.textContent = photos.length > 1 ? '(' + photos.length + ')' : '';
        // A visit with no photo is a visit to add one to.
        if (addPanel && photos.length === 0) addPanel.open = true;

        photos.forEach((photo) => {
            // The whole entry puts its photo on the stage; it never leaves the
            // dialog. "Open full size" is there for that.
            const thumb = el('button', 'cphoto-item');
            thumb.type = 'button';
            thumb.dataset.select = String(photo.id);

            const img = document.createElement('img');
            img.src = photo.url;
            img.alt = '';
            img.loading = 'lazy';
            img.addEventListener('error', () => {
                img.replaceWith(el('span', 'cphoto-item-fallback', 'No preview'));
            }, { once: true });
            thumb.appendChild(img);

            const text = el('span', 'cphoto-item-text');
            const note = el('span', 'cphoto-item-comment', photo.caption || 'No comment');
            if (!photo.caption) note.classList.add('is-uncommented');
            text.appendChild(note);
            text.appendChild(el('span', 'cphoto-item-meta', metaOf(photo)));
            if (photo.shared_with_adviser) text.appendChild(el('span', 'cphoto-item-shared', 'Shared with adviser'));
            thumb.appendChild(text);

            list.appendChild(thumb);
        });

        syncCount();
        showPhoto();
    };

    // Read fresh on every open, so a photo added or replaced at another desk
    // is the one shown. The id is held for the length of the request: a
    // dialog closed and reopened on another visit must never be painted with
    // the first visit's photos.
    const load = async () => {
        if (consultationId === null) return;
        const id = consultationId;

        stageState('loading');
        retry = load;

        try {
            const response = await fetch(indexUrl(id), { headers: { Accept: 'application/json' } });
            if (id !== consultationId) return;
            if (!response.ok) {
                stageFailed(
                    'The photos could not be loaded.',
                    response.status === 401 || response.status === 403
                        ? 'Your session may have ended. Sign in again, then try again.'
                        : 'Check your connection and try again.',
                    null,
                );
                return;
            }
            const data = await response.json();
            showVisit(data.visit || null);
            render(data.photos);
        } catch (_) {
            if (id === consultationId) {
                stageFailed('The photos could not be loaded.', 'Check your connection and try again.', null);
            }
        }
    };

    const open = (id, student) => {
        consultationId = id;
        photos = [];
        currentId = null;
        showError('');
        if (fileInput) fileInput.value = '';
        if (captionInput) captionInput.value = '';
        if (shareInput) shareInput.checked = false;
        if (addPanel) addPanel.open = false;
        if (sub) sub.textContent = student ? 'Photos for ' + student : '';
        list.textContent = '';
        stopEdit();
        if (visitSection) visitSection.hidden = true;
        if (detail) detail.hidden = true;
        if (allPanel) allPanel.hidden = true;
        if (stageCount) stageCount.hidden = true;
        if (prevBtn) prevBtn.hidden = true;
        if (nextBtn) nextBtn.hidden = true;
        // Cancel a leave still in flight rather than letting the two
        // animations fight over the same element.
        backdrop.classList.remove('is-closing');
        backdrop.hidden = false;
        document.body.classList.add('bmodal-open');
        load();
    };

    // Held on screen while it fades, the way the shared .bmodal dialogs are.
    // A dialog that vanishes on the frame the button is pressed reads as a
    // glitch, and on a blurred backdrop the whole page appears to jump.
    const stillMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const close = () => {
        if (backdrop.hidden || backdrop.classList.contains('is-closing')) return;
        if (!leaveEdit()) return;

        const finish = () => {
            backdrop.classList.remove('is-closing');
            backdrop.hidden = true;
            consultationId = null;
            photos = [];
            currentId = null;
            // Stop a load still in flight and let go of the image.
            loadSeq++;
            stageImg?.removeAttribute('src');
            document.body.classList.remove('bmodal-open');
        };

        if (stillMotion.matches) { finish(); return; }

        backdrop.classList.add('is-closing');

        let done = false;
        const settle = () => { if (!done) { done = true; finish(); } };

        // The timeout is a backstop: animationend never fires on a hidden tab.
        backdrop.querySelector('.cphoto-panel')?.addEventListener('animationend', settle, { once: true });
        setTimeout(settle, 300);
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
    retryBtn?.addEventListener('click', () => { if (retry) retry(); });
    prevBtn?.addEventListener('click', () => step(-1));
    nextBtn?.addEventListener('click', () => step(1));

    list?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-select]');
        if (item) select(item.dataset.select);
    });

    document.addEventListener('keydown', (e) => {
        if (backdrop.hidden) return;
        // Esc while writing a comment leaves the comment, not the dialog.
        if (e.key === 'Escape' && editingId !== null) { e.preventDefault(); leaveEdit(); return; }
        if (e.key === 'Escape') { close(); return; }
        // Arrows move between photos, unless someone is typing a comment.
        if (e.target.closest && e.target.closest('input, textarea, select')) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    });

    uploadBtn?.addEventListener('click', async () => {
        if (consultationId === null) return;
        const id = consultationId;
        const file = fileInput?.files && fileInput.files[0];
        if (!file) { showError('Choose a photo first.'); return; }
        // The new photo takes the stage, so a comment half-written on the
        // old one is settled first.
        if (!leaveEdit()) return;

        showError('');
        uploadBtn.disabled = true;

        const body = new FormData();
        body.append('photo', file);
        body.append('caption', captionInput?.value ?? '');
        body.append('shared_with_adviser', shareInput?.checked ? '1' : '0');

        try {
            const response = await fetch(indexUrl(id), {
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

            const data = await response.json();
            if (id !== consultationId) return;
            fileInput.value = '';
            captionInput.value = '';
            shareInput.checked = false;
            if (addPanel) addPanel.open = false;
            // The photo just uploaded goes straight onto the stage.
            render(data.photos, data.photo?.id ?? null);
        } catch (_) {
            showError('The photo could not be uploaded.');
        } finally {
            uploadBtn.disabled = false;
        }
    });

    // Sharing is its own decision, reversible, and made about the photo on
    // screen: a nurse who realises a teacher needs to know should not have to
    // re-upload, and one who shared by mistake must be able to take it back.
    detailShare?.addEventListener('change', async () => {
        if (currentId === null) return;
        const wanted = detailShare.checked;

        try {
            const response = await fetch(photoUrl(currentId) + '/share', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ shared_with_adviser: wanted }),
            });
            if (!response.ok) { detailShare.checked = !wanted; return; }
            render((await response.json()).photos);
        } catch (_) {
            detailShare.checked = !wanted;
        }
    });

    detailRemove?.addEventListener('click', async () => {
        if (currentId === null) return;
        if (!window.confirm('Remove this photo? This is recorded in the audit trail.')) return;
        // A comment being written on a photo that is going is moot.
        stopEdit();

        try {
            const response = await fetch(photoUrl(currentId), {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            });
            if (!response.ok) return;
            render((await response.json()).photos);
        } catch (_) { /* the photo stays until the next successful read */ }
    });

    // Saving a comment: written through the server, then redrawn from the
    // list it answers with, so the stage, the list and the editor agree.
    const saveComment = async () => {
        if (editingId === null || !editText) return;
        const id = editingId;

        showEditError('');
        if (editSave) editSave.disabled = true;

        try {
            const response = await fetch(photoUrl(id) + '/caption', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ caption: editText.value }),
            });

            if (response.status === 422) {
                const data = await response.json();
                const first = Object.values(data.errors || {})[0];
                showEditError(Array.isArray(first) ? first[0] : 'That comment could not be saved.');
                return;
            }
            if (!response.ok) {
                showEditError(response.status === 403
                    ? 'Only the clinic can comment on a photo.'
                    : 'The comment could not be saved. Try again.');
                return;
            }

            const data = await response.json();
            if (String(editingId) !== String(id)) return;
            stopEdit();
            render(data.photos);
        } catch (_) {
            showEditError('The comment could not be saved. Check your connection and try again.');
        } finally {
            if (editSave) editSave.disabled = false;
        }
    };

    editBtn?.addEventListener('click', startEdit);
    editCancel?.addEventListener('click', () => { leaveEdit(); });
    editSave?.addEventListener('click', saveComment);
    editText?.addEventListener('input', countChars);
    editText?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); saveComment(); }
    });

    visitMore?.addEventListener('click', () => {
        const expanded = visitMore.getAttribute('aria-expanded') === 'true';
        visitNotes?.classList.toggle('is-clamped', expanded);
        visitMore.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        visitMore.textContent = expanded ? 'Show more' : 'Show less';
    });
})();
</script>
