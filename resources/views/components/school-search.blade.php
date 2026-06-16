@props([
    'name'        => 'school_name',
    'label'       => 'School',
    'placeholder' => 'Search or scroll to select a school…',
    'schools'     => [],
    'readonly'    => false,
])

<div class="sdd-field">
    <label @if($readonly) style="opacity:.6;" @endif>{{ $label }}</label>

    {{-- Single quotes on the attribute + {!! !!} avoids double-escaping the JSON --}}
    <div class="sdd-wrap" data-schools='{!! json_encode(array_values($schools)) !!}'>
        <div class="sdd-combo">
            <input
                type="text"
                class="sdd-input"
                placeholder="{{ $placeholder }}"
                autocomplete="off"
                spellcheck="false"
                role="combobox"
                aria-haspopup="listbox"
                aria-expanded="false"
                aria-autocomplete="list"
                @if($readonly) readonly @endif
            >
            <button type="button" class="sdd-chevron" tabindex="-1" aria-hidden="true">
                <svg width="11" height="11" viewBox="0 0 12 12" fill="none">
                    <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.8"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        <div class="sdd-panel" role="listbox">
            <div class="sdd-count"></div>
            <div class="sdd-options"></div>
        </div>
    </div>

    <input type="hidden" name="{{ $name }}" class="sdd-value" value="{{ old($name, '') }}">

    @error($name)
        <div class="sdd-err">{{ $message }}</div>
    @enderror
</div>

<style>
.sdd-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sdd-field label {
    font-size: .7rem;
    color: #5b7b68;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-weight: 700;
}

.sdd-wrap {
    position: relative;
}

.sdd-combo {
    position: relative;
    display: flex;
    align-items: center;
}

.sdd-input {
    width: 100%;
    height: 42px;
    border: 1px solid #dbe9df;
    border-radius: 10px;
    padding: 0 36px 0 12px;
    font: inherit;
    font-size: .86rem;
    color: #0f2f1b;
    background: #fff;
    cursor: pointer;
    transition: border-color .18s, box-shadow .18s;
}

.sdd-input:focus {
    outline: none;
    cursor: text;
    border-color: #22c55e;
    box-shadow: 0 0 0 3px rgba(34,197,94,.15);
}

.sdd-input[readonly] {
    background: #f5f5f5;
    color: #999;
    cursor: not-allowed;
}

.sdd-input::placeholder {
    color: #aac4b4;
}

.sdd-chevron {
    position: absolute;
    right: 10px;
    background: none;
    border: none;
    color: #6b9e82;
    cursor: pointer;
    padding: 4px;
    line-height: 0;
    transition: transform .2s;
    pointer-events: none;
}

.sdd-combo.open .sdd-chevron {
    transform: rotate(180deg);
}

/* ── dropdown panel ─────────────────── */
.sdd-panel {
    position: absolute;
    top: calc(100% + 5px);
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #c9e0d3;
    border-radius: 10px;
    box-shadow: 0 6px 20px rgba(20,83,45,.12);
    z-index: 200;
    display: none;
    flex-direction: column;
    max-height: 280px;
}

.sdd-panel.open {
    display: flex;
}

.sdd-count {
    flex-shrink: 0;
    padding: 6px 12px 5px;
    font-size: .68rem;
    font-weight: 700;
    color: #7ba890;
    letter-spacing: .04em;
    text-transform: uppercase;
    border-bottom: 1px solid #eaf2ec;
}

.sdd-options {
    overflow-y: auto;
    flex: 1;
    padding: 4px 0;
}

/* Scrollbar */
.sdd-options::-webkit-scrollbar { width: 5px; }
.sdd-options::-webkit-scrollbar-track { background: transparent; }
.sdd-options::-webkit-scrollbar-thumb { background: #b8d8c6; border-radius: 3px; }

.sdd-opt {
    padding: 9px 13px;
    font-size: .85rem;
    color: #1a3828;
    cursor: pointer;
    border-bottom: 1px solid #f2f8f4;
    transition: background .1s;
    line-height: 1.35;
}

.sdd-opt:last-child { border-bottom: none; }

.sdd-opt:hover,
.sdd-opt.focused {
    background: #f0f7f3;
}

.sdd-opt.selected {
    background: #dcfce7;
    color: #14532d;
    font-weight: 600;
}

.sdd-opt mark {
    background: #fef08a;
    color: inherit;
    border-radius: 2px;
    font-weight: 700;
    padding: 0 1px;
}

.sdd-empty {
    padding: 18px 13px;
    text-align: center;
    font-size: .84rem;
    color: #7ba890;
    font-style: italic;
}

.sdd-err {
    font-size: .74rem;
    color: #b91c1c;
    margin-top: 2px;
}

@media (max-width: 720px) {
    .sdd-panel { max-height: 210px; }
}
</style>

<script>
(function () {
    function initDropdown(field) {
        const wrap      = field.querySelector('.sdd-wrap');
        const combo     = field.querySelector('.sdd-combo');
        const input     = field.querySelector('.sdd-input');
        const panel     = field.querySelector('.sdd-panel');
        const countEl   = field.querySelector('.sdd-count');
        const optionsEl = field.querySelector('.sdd-options');
        const hidden    = field.querySelector('.sdd-value');

        if (input.readOnly) return;

        let schools;
        try {
            schools = JSON.parse(wrap.dataset.schools || '[]');
        } catch (e) {
            schools = [];
        }

        let isOpen     = false;
        let focusedIdx = -1;
        let committed  = hidden.value; // last confirmed value

        // Restore pre-selected value on page load
        if (committed) input.value = committed;

        /* ── open/close ── */
        function open() {
            if (isOpen) return;
            isOpen = true;
            combo.classList.add('open');
            panel.classList.add('open');
            input.setAttribute('aria-expanded', 'true');
            render('');                         // show ALL schools immediately
            requestAnimationFrame(() => {
                const sel = optionsEl.querySelector('.sdd-opt.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            });
        }

        function close(restorePrev) {
            if (!isOpen) return;
            isOpen     = false;
            focusedIdx = -1;
            combo.classList.remove('open');
            panel.classList.remove('open');
            input.setAttribute('aria-expanded', 'false');
            if (restorePrev) input.value = committed;
        }

        function pick(school) {
            committed    = school;
            hidden.value = school;
            input.value  = school;
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            close(false);
        }

        /* ── render list ── */
        function render(query) {
            const q    = query.trim().toLowerCase();
            const list = q ? schools.filter(s => s.toLowerCase().includes(q)) : schools;

            focusedIdx         = -1;
            optionsEl.innerHTML = '';
            countEl.textContent = q
                ? `${list.length} result${list.length !== 1 ? 's' : ''} for "${query.trim()}"`
                : `${list.length} schools — scroll or type to search`;

            if (list.length === 0) {
                const el = document.createElement('div');
                el.className = 'sdd-empty';
                el.textContent = 'No schools match your search.';
                optionsEl.appendChild(el);
                return;
            }

            list.forEach((school, idx) => {
                const opt = document.createElement('div');
                opt.className = 'sdd-opt';
                opt.setAttribute('role', 'option');
                if (school === committed) opt.classList.add('selected');

                if (q) {
                    const lo = school.toLowerCase();
                    let html = '', cursor = 0, pos;
                    while ((pos = lo.indexOf(q, cursor)) !== -1) {
                        html  += esc(school.slice(cursor, pos));
                        html  += `<mark>${esc(school.slice(pos, pos + q.length))}</mark>`;
                        cursor = pos + q.length;
                    }
                    opt.innerHTML = html + esc(school.slice(cursor));
                } else {
                    opt.textContent = school;
                }

                opt.addEventListener('mousedown', e => {
                    e.preventDefault(); // keep input focused
                    pick(school);
                });
                opt.addEventListener('mousemove', () => setFocus(idx));

                optionsEl.appendChild(opt);
            });
        }

        function setFocus(idx) {
            const opts = optionsEl.querySelectorAll('.sdd-opt');
            if (opts[focusedIdx]) opts[focusedIdx].classList.remove('focused');
            focusedIdx = idx;
            if (opts[focusedIdx]) {
                opts[focusedIdx].classList.add('focused');
                opts[focusedIdx].scrollIntoView({ block: 'nearest' });
            }
        }

        function esc(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        /* ── event wiring ── */
        // Open on click or focus
        input.addEventListener('mousedown', e => {
            if (!isOpen) {
                e.preventDefault();
                input.focus();
                open();
            }
        });

        input.addEventListener('focus', () => {
            if (!isOpen) open();
        });

        // Filter as user types
        input.addEventListener('input', () => {
            if (!isOpen) open();
            render(input.value);
        });

        // Keyboard nav
        input.addEventListener('keydown', e => {
            const opts = optionsEl.querySelectorAll('.sdd-opt');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) open();
                setFocus(Math.min(focusedIdx + 1, opts.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setFocus(Math.max(focusedIdx - 1, 0));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (isOpen && focusedIdx >= 0 && opts[focusedIdx]) {
                    const txt = opts[focusedIdx].textContent;
                    pick(schools.find(s => s === txt) || txt);
                }
            } else if (e.key === 'Escape' || e.key === 'Tab') {
                close(true);
            }
        });

        // Close when focus leaves the whole field
        field.addEventListener('focusout', e => {
            if (!field.contains(e.relatedTarget)) {
                setTimeout(() => { if (!field.contains(document.activeElement)) close(true); }, 100);
            }
        });

        // Close on outside click
        document.addEventListener('mousedown', e => {
            if (isOpen && !field.contains(e.target)) close(true);
        });
    }

    function init() {
        document.querySelectorAll('.sdd-field').forEach(initDropdown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
