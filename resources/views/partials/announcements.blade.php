{{--
    Dashboard announcements board, shared by every role's dashboard.
    Only Announcement::POSTER_ROLES (school_nurse, for now) can post/remove —
    everyone else sees a read-only list. Self-contained styles so this can be
    @included on any page regardless of that page's own CSS.
--}}
@php
    use App\Models\Announcement;
    $announcementRole = (string) session('active_role', '');
    $canPostAnnouncement = Announcement::canPost($announcementRole);
    $annLimit = Announcement::BOARD_LIMIT;
    $hasAnnouncementsTable = \App\Support\SchemaCache::hasTable('announcements');

    // Only what this role was addressed to, and only what is still on the
    // board. An announcement with no audience goes to everyone; the author
    // always sees their own; an archived one is off the board for every role,
    // the nurse who archived it included.
    // Ordered by id as well as time, not by time alone: two notices posted in
    // the same second tie on created_at, and a tie leaves the order to the
    // database — which decided, on a board capped at four, *which* four.
    $announcements = $hasAnnouncementsTable
        ? Announcement::forActiveInstitution()
            ->visibleToRole($announcementRole)
            ->active()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($annLimit)
            ->get()
        : collect();

    // How many notices the board is holding back. Counted only when the page
    // is already full, so a dashboard with a handful of announcements spends
    // no query on the question — the adviser dashboard runs to a query budget.
    $annTotal = $announcements->count() < $annLimit
        ? $announcements->count()
        : Announcement::forActiveInstitution()->visibleToRole($announcementRole)->active()->count();

    // The archive controls appear only where the column behind them exists.
    // A machine that has pulled this code but not yet run the migration keeps
    // the board it had — posting and removing — rather than being offered an
    // Archive button that would fail on the write. A control that errors is a
    // worse answer than a control that is not there yet.
    $canArchiveAnnouncement = $canPostAnnouncement && $hasAnnouncementsTable && Announcement::supportsArchiving();

    // The archive is the poster's own working list, so it is read for nobody
    // else. Not filtered by audience: a nurse archiving their own board should
    // see everything they took off it, including notices they addressed to one
    // role.
    $archivedAnnouncements = $canArchiveAnnouncement
        ? Announcement::forActiveInstitution()->archived()->orderByDesc('archived_at')->orderByDesc('id')->limit(50)->get()
        : collect();
@endphp
{{--
    Colours read from --ann-* custom properties with the original values as
    fallbacks, so a role's stylesheet can retheme this board (the Feeding
    Coordinator does, in lusog-theme.css) without changing it anywhere else.
--}}
<style>
    .ann-board { background: #fff; border: 1px solid var(--ann-border, #DCE8E0); border-radius: var(--ann-radius, 12px); box-shadow: var(--ann-shadow, 0 1px 4px rgba(5,46,22,.06)); margin-bottom: 18px; }
    .ann-board-head { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--ann-border, #DCE8E0); }
    .ann-board-head svg { width: 17px; height: 17px; color: var(--ann-icon, #1F8A4C); flex-shrink: 0; }
    .ann-board-title { font-size: .82rem; font-weight: 700; color: var(--ann-title, #1F2D25); letter-spacing: .01em; }
    .ann-board-sub { font-size: .72rem; color: var(--ann-sub, #6B7C72); margin-left: 4px; }
    .ann-post-toggle { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; background: var(--ann-btn-bg, #126B3A); color: #fff; border: none; border-radius: 8px; padding: 7px 13px; font-size: .76rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-post-toggle:hover { background: var(--ann-btn-bg-hover, #0d3d20); }
    .ann-post-form { display: none; padding: 14px 18px; border-bottom: 1px solid #DCE8E0; background: #f7faf8; }
    .ann-post-form.open { display: block; }
    .ann-post-form input, .ann-post-form textarea { width: 100%; border: 1px solid #d1dbd5; border-radius: 8px; padding: 8px 10px; font-family: inherit; font-size: .82rem; color: #1d3c31; margin-bottom: 8px; box-sizing: border-box; }
    .ann-post-form textarea { min-height: 64px; resize: vertical; }
    .ann-post-form .ann-form-actions { display: flex; gap: 8px; }
    .ann-btn-primary { background: #1F8A4C; color: #fff; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-btn-ghost { background: #eef3f0; color: #3E5348; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-flash { margin: 10px 18px 0; padding: 8px 12px; border-radius: 8px; font-size: .78rem; background: #E7F5EC; color: #14653C; border: 1px solid #BFE3CC; }
    .ann-list { max-height: 320px; overflow-y: auto; }
    .ann-item { padding: 12px 18px; border-bottom: 1px solid var(--ann-border, #eef3f0); }
    .ann-item:last-child { border-bottom: none; }
    .ann-item-top { display: flex; align-items: baseline; gap: 8px; margin-bottom: 3px; }
    .ann-item-title { font-size: .84rem; font-weight: 700; color: var(--ann-title, #14321f); }
    .ann-item-time { font-size: .68rem; color: var(--ann-sub, #94a3b8); margin-left: auto; white-space: nowrap; }
    .ann-item-body { font-size: .8rem; color: var(--ann-body, #3E5348); line-height: 1.5; white-space: pre-line; }
    .ann-item-meta { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
    .ann-item-by { font-size: .7rem; color: var(--ann-sub, #6B7C72); }
    /* Archive sits before Remove and reads quieter than it: taking a notice
       off the board is the routine act, destroying it the exceptional one. */
    .ann-item-actions { margin-left: auto; display: flex; align-items: center; gap: 2px; }
    .ann-item-delete-form { display: inline; }
    .ann-item-delete-btn { background: none; border: none; border-radius: 5px; color: #b91c1c; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; padding: 2px 6px; }
    /* Tinted, never underlined — nothing in this app rules a line through a word. */
    .ann-item-delete-btn:hover { background: #FCECEC; }
    .ann-item-archive-btn { background: none; border: none; border-radius: 5px; color: #3E5348; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; padding: 2px 6px; }
    .ann-item-archive-btn:hover { background: #eef3f0; color: #126B3A; }
    .ann-empty { padding: 22px 18px; text-align: center; color: var(--ann-empty, #94a3b8); font-size: .8rem; }

    /* The board holds BOARD_LIMIT notices. When there are more, it says so —
       an announcement that scrolled off silently is an announcement the nurse
       thinks is still up. */
    .ann-more-note { padding: 9px 18px; border-top: 1px solid var(--ann-border, #eef3f0); font-size: .72rem; color: var(--ann-sub, #6B7C72); background: #f7faf8; }

    /* Archive button in the head. Ghost, not a second primary: green is the
       brand and the healthy signal, not decoration for every control. */
    .ann-archive-toggle { display: inline-flex; align-items: center; gap: 6px; background: #eef3f0; color: #3E5348; border: none; border-radius: 8px; padding: 7px 12px; font-size: .76rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-archive-toggle:hover { background: #e2eae5; color: #126B3A; }
    .ann-archive-toggle svg { width: 14px; height: 14px; }
    .ann-archive-count { font-variant-numeric: tabular-nums; }
    /* The wrapper now does the pushing, so the post button must stop: its own
       margin-left:auto would otherwise re-push it inside the wrapper and open
       a gap between the two head buttons. */
    .ann-head-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
    .ann-head-actions .ann-post-toggle { margin-left: 0; }

    /* Archived list inside the dialog. Same anatomy as a board item, one
       step quieter, with the two actions that belong to an archived notice. */
    .ann-arch-item { padding: 11px 0; border-bottom: 1px solid #eef3f0; }
    .ann-arch-item:last-child { border-bottom: none; }
    .ann-arch-top { display: flex; align-items: baseline; gap: 8px; }
    .ann-arch-title { font-size: .82rem; font-weight: 700; color: #14321f; }
    .ann-arch-time { font-size: .68rem; color: #94a3b8; margin-left: auto; white-space: nowrap; }
    .ann-arch-body { font-size: .78rem; color: #3E5348; line-height: 1.5; white-space: pre-line; margin-top: 3px; }
    .ann-arch-meta { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
    .ann-arch-by { font-size: .68rem; color: #6B7C72; }
    .ann-arch-actions { margin-left: auto; display: flex; align-items: center; gap: 2px; }
    .ann-arch-restore-btn { background: none; border: none; border-radius: 5px; color: #126B3A; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; padding: 2px 6px; }
    .ann-arch-restore-btn:hover { background: #E7F5EC; }
    .ann-arch-empty { padding: 20px 0; text-align: center; color: #94a3b8; font-size: .8rem; }

    /* Priority. Urgent takes the system's critical coral, Important its
       monitoring amber; a normal notice shows no chip at all, so the two
       that matter are the only coloured things in the list. Never colour
       alone — each chip carries its word. */
    .ann-pill { font-size: .6rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; flex-shrink: 0; }
    .ann-pill-urgent { background: #FCECEC; color: #b91c1c; box-shadow: inset 0 0 0 1px #F2C9C9; }
    .ann-pill-important { background: #FDF4E2; color: #8A5A06; box-shadow: inset 0 0 0 1px #EFDCB2; }
    /* An urgent notice also gets a left rule, so it survives greyscale. */
    .ann-item.is-urgent { border-left: 3px solid #D95C5C; }
    .ann-item.is-important { border-left: 3px solid #F2B84B; }
    .ann-item-audience { font-size: .68rem; color: var(--ann-sub, #6B7C72); }

    .ann-aud-note { font-size: .72rem; color: #6B7C72; margin-top: 6px; }
</style>

<div class="ann-board">
    <div class="ann-board-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a2 2 0 1 1-3.2 2.4"/></svg>
        <span class="ann-board-title">Announcements</span>
        <span class="ann-board-sub">from the School Nurse</span>
        @if ($canPostAnnouncement)
            <div class="ann-head-actions">
                @if ($canArchiveAnnouncement)
                <button type="button" class="ann-archive-toggle" data-bmodal-open="annArchiveModal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/></svg>
                    {{-- `Archived@if` would not compile: Blade needs a non-word
                         character before a directive, so a word run straight
                         into @if leaves the closing @endif unmatched. --}}
                    Archived
                    @if ($archivedAnnouncements->isNotEmpty())
                        <span class="ann-archive-count">({{ $archivedAnnouncements->count() }})</span>
                    @endif
                </button>
                @endif
                <button type="button" class="ann-post-toggle" data-bmodal-open="annPostModal">+ Post Announcement</button>
            </div>
        @endif
    </div>

    @if (session('announcement_success'))
        <div class="ann-flash">{{ session('announcement_success') }}</div>
    @endif

    @if ($announcements->isEmpty())
        <div class="ann-empty">No announcements yet.</div>
    @else
        <div class="ann-list">
            @foreach ($announcements as $item)
                <div class="ann-item {{ $item->priority === Announcement::PRIORITY_URGENT ? 'is-urgent' : ($item->priority === Announcement::PRIORITY_IMPORTANT ? 'is-important' : '') }}">
                    <div class="ann-item-top">
                        @if ($item->isFlagged())
                            <span class="ann-pill ann-pill-{{ $item->priority }}">{{ $item->priorityLabel() }}</span>
                        @endif
                        <span class="ann-item-title">{{ $item->title }}</span>
                        <span class="ann-item-time">{{ $item->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="ann-item-body">{{ $item->body }}</div>
                    <div class="ann-item-meta">
                        <span class="ann-item-by">&mdash; {{ $item->posted_by_name }}</span>
                        @if ($canPostAnnouncement)
                            {{-- Only the poster needs to see who it went to. --}}
                            <span class="ann-item-audience">&middot; To: {{ $item->audienceLabel() }}</span>
                        @endif
                        @if ($canPostAnnouncement)
                            <div class="ann-item-actions">
                                {{-- Archive first: it is the routine act, and it asks
                                     for no confirmation because it undoes in one click. --}}
                                @if ($canArchiveAnnouncement)
                                    <form method="POST" action="{{ route('announcements.archive', $item) }}" class="ann-item-delete-form">
                                        @csrf
                                        <button type="submit" class="ann-item-archive-btn">Archive</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('announcements.destroy', $item) }}" class="ann-item-delete-form" onsubmit="return confirm('Remove this announcement permanently? Archive it instead to keep it on record.');">
                                    @csrf
                                    <button type="submit" class="ann-item-delete-btn">Remove</button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($annTotal > $annLimit)
            <div class="ann-more-note">
                Showing the {{ $annLimit }} most recent of {{ $annTotal }}.
                @if ($canPostAnnouncement)
                    Archive the ones that have passed to bring the older notices up.
                @endif
            </div>
        @endif
    @endif
</div>

@if ($canPostAnnouncement)
    @include('partials.board-modal-assets')

    {{-- The archive. Read-only history plus the two actions an archived
         notice has: put it back, or destroy it. Outside .ann-board for the
         same reason as the post dialog below. --}}
    @if ($canArchiveAnnouncement)
    <div class="bmodal" id="annArchiveModal" role="dialog" aria-modal="true" aria-labelledby="annArchiveModalTitle">
        <div class="bmodal-panel">
            <div class="bmodal-head">
                <div>
                    <div class="bmodal-eyebrow">Announcements</div>
                    <div class="bmodal-title" id="annArchiveModalTitle">Archived announcements</div>
                    <div class="bmodal-sub">Off the board, still on record at {{ session('active_school_name', 'this school') }}.</div>
                </div>
                <button type="button" class="bmodal-close" data-bmodal-close aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="bmodal-body">
                @if ($archivedAnnouncements->isEmpty())
                    <div class="ann-arch-empty">
                        Nothing archived yet.<br>
                        Archiving takes a notice off the board without deleting it.
                    </div>
                @else
                    @foreach ($archivedAnnouncements as $archived)
                        <div class="ann-arch-item">
                            <div class="ann-arch-top">
                                @if ($archived->isFlagged())
                                    <span class="ann-pill ann-pill-{{ $archived->priority }}">{{ $archived->priorityLabel() }}</span>
                                @endif
                                <span class="ann-arch-title">{{ $archived->title }}</span>
                                <span class="ann-arch-time">Archived {{ $archived->archived_at?->diffForHumans() }}</span>
                            </div>
                            <div class="ann-arch-body">{{ $archived->body }}</div>
                            <div class="ann-arch-meta">
                                <span class="ann-arch-by">
                                    &mdash; {{ $archived->posted_by_name }}
                                    &middot; To: {{ $archived->audienceLabel() }}
                                    @if ($archived->archived_by_name)
                                        &middot; archived by {{ $archived->archived_by_name }}
                                    @endif
                                </span>
                                <div class="ann-arch-actions">
                                    <form method="POST" action="{{ route('announcements.restore', $archived) }}" class="ann-item-delete-form">
                                        @csrf
                                        <button type="submit" class="ann-arch-restore-btn">Restore</button>
                                    </form>
                                    <form method="POST" action="{{ route('announcements.destroy', $archived) }}" class="ann-item-delete-form" onsubmit="return confirm('Delete this announcement permanently? This cannot be undone.');">
                                        @csrf
                                        <button type="submit" class="ann-item-delete-btn">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="bmodal-foot">
                <button type="button" class="bmodal-btn bmodal-btn-ghost" data-bmodal-close>Close</button>
            </div>
        </div>
    </div>
    @endif

    {{-- Outside .ann-board on purpose: the backdrop is position:fixed and
         must blur the whole dashboard, not sit inside one card. --}}
    <div class="bmodal" id="annPostModal" role="dialog" aria-modal="true" aria-labelledby="annPostModalTitle"
         @if ($errors->announcement->any()) data-bmodal-autoopen @endif>
        <div class="bmodal-panel">
            <form method="POST" action="{{ route('announcements.store') }}">
                @csrf
                <div class="bmodal-head">
                    <div>
                        <div class="bmodal-eyebrow">Announcements</div>
                        <div class="bmodal-title" id="annPostModalTitle">Post an announcement</div>
                        <div class="bmodal-sub">Visible on every staff dashboard at {{ session('active_school_name', 'this school') }}.</div>
                    </div>
                    <button type="button" class="bmodal-close" data-bmodal-close aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="bmodal-body">
                    <div class="bmodal-field">
                        <label for="annTitle">Title</label>
                        <input type="text" id="annTitle" name="title" maxlength="150" required
                               placeholder="e.g. Deworming schedule this Friday"
                               value="{{ old('title') }}">
                        @if ($errors->announcement->has('title'))
                            <div class="bmodal-error">{{ $errors->announcement->first('title') }}</div>
                        @endif
                    </div>

                    <div class="bmodal-field">
                        <label for="annPriority">Priority</label>
                        <select id="annPriority" name="priority" required>
                            @foreach (Announcement::PRIORITIES as $key => $label)
                                <option value="{{ $key }}" @selected(old('priority', Announcement::PRIORITY_NORMAL) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if ($errors->announcement->has('priority'))
                            <div class="bmodal-error">{{ $errors->announcement->first('priority') }}</div>
                        @endif
                    </div>

                    <div class="bmodal-field">
                        <label for="annBody">Announcement</label>
                        <textarea id="annBody" name="body" maxlength="2000" required
                                  placeholder="Write the announcement...">{{ old('body') }}</textarea>
                        @if ($errors->announcement->has('body'))
                            <div class="bmodal-error">{{ $errors->announcement->first('body') }}</div>
                        @endif
                    </div>

                    <div class="bmodal-field">
                        <label for="annAudience">Audience</label>
                        @php
                            // A single-select posts into audience[], because the
                            // column stores a list — addressing one announcement
                            // to several roles stays possible without a schema
                            // change if this ever grows a multi-picker.
                            $chosenAudience = (array) old('audience', []);
                            $chosenAudience = $chosenAudience[0] ?? '';
                        @endphp
                        <select id="annAudience" name="audience[]">
                            <option value="" @selected($chosenAudience === '')>Everyone</option>
                            @foreach (Announcement::AUDIENCES as $key => $label)
                                <option value="{{ $key }}" @selected($chosenAudience === $key)>{{ $label }} only</option>
                            @endforeach
                        </select>
                        <div class="ann-aud-note">Everyone means all staff at this school.</div>
                        @if ($errors->announcement->has('audience') || $errors->announcement->has('audience.0'))
                            <div class="bmodal-error">{{ $errors->announcement->first('audience') ?: $errors->announcement->first('audience.0') }}</div>
                        @endif
                    </div>
                </div>

                <div class="bmodal-foot">
                    <button type="button" class="bmodal-btn bmodal-btn-ghost" data-bmodal-close>Cancel</button>
                    <button type="submit" class="bmodal-btn bmodal-btn-primary">Post announcement</button>
                </div>
            </form>
        </div>
    </div>
@endif
