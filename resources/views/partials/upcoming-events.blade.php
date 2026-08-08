{{--
    Upcoming events board, shared across dashboards. Only Event::CREATOR_ROLES
    (school_nurse, for now) can create/remove — everyone else sees a
    read-only list. Self-contained styles, same pattern as
    partials/announcements.blade.php.
--}}
@php
    use App\Models\Event;
    $canCreateEvent = Event::canCreate(session('active_role'));
    $upcomingEvents = \App\Support\SchemaCache::hasTable('events')
        ? Event::forActiveInstitution()->upcoming()->limit(6)->get()
        : collect();
@endphp
<style>
    .evt-board { background: #fff; border: 1px solid #e4ece7; border-radius: 12px; box-shadow: 0 1px 4px rgba(5,46,22,.06); margin-bottom: 18px; }
    .evt-board-head { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid #e4ece7; }
    .evt-board-head svg { width: 17px; height: 17px; color: #15803d; flex-shrink: 0; }
    .evt-board-title { font-size: .82rem; font-weight: 700; color: #0d1f14; }
    .evt-board-sub { font-size: .72rem; color: #6f8c7a; margin-left: 4px; }
    .evt-add-toggle { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; background: #14532d; color: #fff; border: none; border-radius: 8px; padding: 7px 13px; font-size: .76rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .evt-add-toggle:hover { background: #0d3d20; }
    .evt-add-form { display: none; padding: 14px 18px; border-bottom: 1px solid #e4ece7; background: #f7faf8; }
    .evt-add-form.open { display: block; }
    .evt-add-form input, .evt-add-form select, .evt-add-form textarea { width: 100%; border: 1px solid #d1dbd5; border-radius: 8px; padding: 8px 10px; font-family: inherit; font-size: .82rem; color: #1d3c31; margin-bottom: 8px; box-sizing: border-box; }
    .evt-add-form textarea { min-height: 54px; resize: vertical; }
    .evt-add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .evt-form-actions { display: flex; gap: 8px; }
    .evt-btn-primary { background: #15803d; color: #fff; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .evt-btn-ghost { background: #eef3f0; color: #3d5c47; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .evt-flash { margin: 10px 18px 0; padding: 8px 12px; border-radius: 8px; font-size: .78rem; background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .evt-list { max-height: 340px; overflow-y: auto; }
    .evt-item { display: flex; gap: 12px; padding: 12px 18px; border-bottom: 1px solid #eef3f0; }
    .evt-item:last-child { border-bottom: none; }
    .evt-date-badge { flex-shrink: 0; width: 52px; text-align: center; background: #f0fdf4; border: 1px solid #d3ecdd; border-radius: 9px; padding: 5px 0; }
    .evt-date-month { font-size: .62rem; font-weight: 700; color: #15803d; text-transform: uppercase; letter-spacing: .04em; }
    .evt-date-day { font-size: 1.05rem; font-weight: 800; color: #0d1f14; line-height: 1.1; }
    .evt-item-body { flex: 1; min-width: 0; }
    .evt-item-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .evt-item-title { font-size: .84rem; font-weight: 700; color: #14321f; }
    .evt-cat-badge { font-size: .62rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }
    .evt-cat-deadline { background: #fee2e2; color: #b91c1c; }
    .evt-cat-program { background: #dcfce7; color: #166534; }
    .evt-item-desc { font-size: .78rem; color: #6f8c7a; margin-top: 3px; }
    .evt-item-delete-form { margin-left: auto; flex-shrink: 0; }
    .evt-item-delete-btn { background: none; border: none; color: #b91c1c; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; padding: 2px 6px; white-space: nowrap; }
    .evt-item-delete-btn:hover { text-decoration: underline; }
    .evt-empty { padding: 22px 18px; text-align: center; color: #94a3b8; font-size: .8rem; }
</style>

<div class="evt-board">
    <div class="evt-board-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="evt-board-title">Upcoming Events</span>
        <span class="evt-board-sub">scheduled by School Nurse</span>
        @if ($canCreateEvent)
            <button type="button" class="evt-add-toggle" onclick="document.getElementById('evtAddForm').classList.toggle('open')">+ Add Event</button>
        @endif
    </div>

    @if (session('event_success'))
        <div class="evt-flash">{{ session('event_success') }}</div>
    @endif

    @if ($canCreateEvent)
        <div class="evt-add-form" id="evtAddForm">
            <form method="POST" action="{{ route('events.store') }}">
                @csrf
                <input type="text" name="title" placeholder="Event title" maxlength="150" required value="{{ old('title') }}">
                <div class="evt-add-grid">
                    <input type="date" name="event_date" required value="{{ old('event_date') }}">
                    <select name="category" required>
                        @foreach (Event::CATEGORIES as $key => $label)
                            <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <textarea name="description" placeholder="Description (optional)" maxlength="1000">{{ old('description') }}</textarea>
                @error('title') <div style="color:#b91c1c;font-size:.74rem;margin-bottom:8px;">{{ $message }}</div> @enderror
                @error('event_date') <div style="color:#b91c1c;font-size:.74rem;margin-bottom:8px;">{{ $message }}</div> @enderror
                <div class="evt-form-actions">
                    <button type="submit" class="evt-btn-primary">Schedule</button>
                    <button type="button" class="evt-btn-ghost" onclick="document.getElementById('evtAddForm').classList.remove('open')">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    @if ($upcomingEvents->isEmpty())
        <div class="evt-empty">No upcoming events.</div>
    @else
        <div class="evt-list">
            @foreach ($upcomingEvents as $item)
                <div class="evt-item">
                    <div class="evt-date-badge">
                        <div class="evt-date-month">{{ $item->event_date->format('M') }}</div>
                        <div class="evt-date-day">{{ $item->event_date->format('d') }}</div>
                    </div>
                    <div class="evt-item-body">
                        <div class="evt-item-top">
                            <span class="evt-item-title">{{ $item->title }}</span>
                            <span class="evt-cat-badge evt-cat-{{ $item->category }}">{{ $item->categoryLabel() }}</span>
                        </div>
                        @if ($item->description)
                            <div class="evt-item-desc">{{ $item->description }}</div>
                        @endif
                    </div>
                    @if ($canCreateEvent)
                        <form method="POST" action="{{ route('events.destroy', $item) }}" class="evt-item-delete-form" onsubmit="return confirm('Remove this event?');">
                            @csrf
                            <button type="submit" class="evt-item-delete-btn">Remove</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
