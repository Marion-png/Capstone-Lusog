{{-- One transmission's status on the shared badge scale. --}}
@php
    [$fxBadgeClass, $fxBadgeLabel] = match ($status) {
        'sent' => ['badge-normal', 'Sent'],
        'blocked' => ['badge-critical', 'Refused'],
        'pending' => ['badge-monitor', 'Pending'],
        default => ['badge-risk', 'Failed'],
    };
@endphp
<span class="badge {{ $fxBadgeClass }}">{{ $fxBadgeLabel }}</span>
