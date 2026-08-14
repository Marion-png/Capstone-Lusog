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
    // Only what this role was addressed to. An announcement with no audience
    // goes to everyone; the author always sees their own.
    $announcements = \App\Support\SchemaCache::hasTable('announcements')
        ? Announcement::forActiveInstitution()
            ->visibleToRole($announcementRole)
            ->latest()
            ->limit(6)
            ->get()
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
    .ann-item-delete-form { margin-left: auto; }
    .ann-item-delete-btn { background: none; border: none; color: #b91c1c; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; padding: 2px 6px; }
    .ann-item-delete-btn:hover { text-decoration: underline; }
    .ann-empty { padding: 22px 18px; text-align: center; color: var(--ann-empty, #94a3b8); font-size: .8rem; }

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
            <button type="button" class="ann-post-toggle" data-bmodal-open="annPostModal">+ Post Announcement</button>
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
                            <form method="POST" action="{{ route('announcements.destroy', $item) }}" class="ann-item-delete-form" onsubmit="return confirm('Remove this announcement?');">
                                @csrf
                                <button type="submit" class="ann-item-delete-btn">Remove</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if ($canPostAnnouncement)
    @include('partials.board-modal-assets')

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
