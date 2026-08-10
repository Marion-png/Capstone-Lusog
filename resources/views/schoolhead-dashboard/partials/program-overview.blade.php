{{--
    Program Overview rows. Every value comes from the school's own records
    (uploaded attendance, consent forms on file, submitted assessments) — no
    fixed schedule. Rendered on first paint and re-rendered into #sh-programs
    by the live refresh. Needs $programs.

    The controller's tone maps onto the shared status scale, so a programme
    badge here reads the same as a learner badge anywhere else in the system.
--}}
@php
    $toneBadge = ['ok' => 'badge-normal', 'warn' => 'badge-monitor', 'idle' => 'badge-neutral'];
@endphp
@foreach ($programs as $program)
    <div class="program-item">
        <div>
            <div class="program-label">{{ $program['label'] }}</div>
            <div class="program-sub">{{ $program['detail'] }}</div>
            @if (! empty($program['note']))
                <div class="program-note">{{ $program['note'] }}</div>
            @endif
        </div>
        <span class="badge {{ $toneBadge[$program['tone']] ?? 'badge-neutral' }}">{{ $program['status'] }}</span>
    </div>
@endforeach
