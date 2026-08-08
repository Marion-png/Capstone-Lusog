{{--
    Dashboard announcements board, shared by every role's dashboard.
    Only Announcement::POSTER_ROLES (school_nurse, for now) can post/remove —
    everyone else sees a read-only list. Self-contained styles so this can be
    @included on any page regardless of that page's own CSS.
--}}
@php
    use App\Models\Announcement;
    $canPostAnnouncement = Announcement::canPost(session('active_role'));
    $announcements = \App\Support\SchemaCache::hasTable('announcements')
        ? Announcement::forActiveInstitution()->latest()->limit(6)->get()
        : collect();
@endphp
<style>
    .ann-board { background: #fff; border: 1px solid #e4ece7; border-radius: 12px; box-shadow: 0 1px 4px rgba(5,46,22,.06); margin-bottom: 18px; }
    .ann-board-head { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid #e4ece7; }
    .ann-board-head svg { width: 17px; height: 17px; color: #15803d; flex-shrink: 0; }
    .ann-board-title { font-size: .82rem; font-weight: 700; color: #0d1f14; letter-spacing: .01em; }
    .ann-board-sub { font-size: .72rem; color: #6f8c7a; margin-left: 4px; }
    .ann-post-toggle { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; background: #14532d; color: #fff; border: none; border-radius: 8px; padding: 7px 13px; font-size: .76rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-post-toggle:hover { background: #0d3d20; }
    .ann-post-form { display: none; padding: 14px 18px; border-bottom: 1px solid #e4ece7; background: #f7faf8; }
    .ann-post-form.open { display: block; }
    .ann-post-form input, .ann-post-form textarea { width: 100%; border: 1px solid #d1dbd5; border-radius: 8px; padding: 8px 10px; font-family: inherit; font-size: .82rem; color: #1d3c31; margin-bottom: 8px; box-sizing: border-box; }
    .ann-post-form textarea { min-height: 64px; resize: vertical; }
    .ann-post-form .ann-form-actions { display: flex; gap: 8px; }
    .ann-btn-primary { background: #15803d; color: #fff; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-btn-ghost { background: #eef3f0; color: #3d5c47; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .ann-flash { margin: 10px 18px 0; padding: 8px 12px; border-radius: 8px; font-size: .78rem; background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .ann-list { max-height: 320px; overflow-y: auto; }
    .ann-item { padding: 12px 18px; border-bottom: 1px solid #eef3f0; }
    .ann-item:last-child { border-bottom: none; }
    .ann-item-top { display: flex; align-items: baseline; gap: 8px; margin-bottom: 3px; }
    .ann-item-title { font-size: .84rem; font-weight: 700; color: #14321f; }
    .ann-item-time { font-size: .68rem; color: #94a3b8; margin-left: auto; white-space: nowrap; }
    .ann-item-body { font-size: .8rem; color: #3d5c47; line-height: 1.5; white-space: pre-line; }
    .ann-item-meta { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
    .ann-item-by { font-size: .7rem; color: #7a9e87; }
    .ann-item-delete-form { margin-left: auto; }
    .ann-item-delete-btn { background: none; border: none; color: #b91c1c; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; padding: 2px 6px; }
    .ann-item-delete-btn:hover { text-decoration: underline; }
    .ann-empty { padding: 22px 18px; text-align: center; color: #94a3b8; font-size: .8rem; }
</style>

<div class="ann-board">
    <div class="ann-board-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a2 2 0 1 1-3.2 2.4"/></svg>
        <span class="ann-board-title">Announcements</span>
        <span class="ann-board-sub">from the School Nurse</span>
        @if ($canPostAnnouncement)
            <button type="button" class="ann-post-toggle" onclick="document.getElementById('annPostForm').classList.toggle('open')">+ Post Announcement</button>
        @endif
    </div>

    @if (session('announcement_success'))
        <div class="ann-flash">{{ session('announcement_success') }}</div>
    @endif

    @if ($canPostAnnouncement)
        <div class="ann-post-form" id="annPostForm">
            <form method="POST" action="{{ route('announcements.store') }}">
                @csrf
                <input type="text" name="title" placeholder="Announcement title" maxlength="150" required value="{{ old('title') }}">
                <textarea name="body" placeholder="Write the announcement..." maxlength="2000" required>{{ old('body') }}</textarea>
                @error('title') <div style="color:#b91c1c;font-size:.74rem;margin-bottom:8px;">{{ $message }}</div> @enderror
                @error('body') <div style="color:#b91c1c;font-size:.74rem;margin-bottom:8px;">{{ $message }}</div> @enderror
                <div class="ann-form-actions">
                    <button type="submit" class="ann-btn-primary">Post</button>
                    <button type="button" class="ann-btn-ghost" onclick="document.getElementById('annPostForm').classList.remove('open')">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    @if ($announcements->isEmpty())
        <div class="ann-empty">No announcements yet.</div>
    @else
        <div class="ann-list">
            @foreach ($announcements as $item)
                <div class="ann-item">
                    <div class="ann-item-top">
                        <span class="ann-item-title">{{ $item->title }}</span>
                        <span class="ann-item-time">{{ $item->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="ann-item-body">{{ $item->body }}</div>
                    <div class="ann-item-meta">
                        <span class="ann-item-by">&mdash; {{ $item->posted_by_name }}</span>
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
