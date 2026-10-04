{{--
    Renders whichever clinic rail belongs to the signed-in role.

    Health Records, Consultation Log and Medicine Inventory are shared by
    the School Nurse, the Clinic Teacher and Clinic Staff. They used to
    include the nurse rail unconditionally, so a Clinic Staff session that
    clicked Consultation Log landed on a page wearing the nurse's navigation
    — Review Queue, health programmes — none of which it may use. It read as
    being thrown onto "the nurse's side".

    Include this instead of a rail partial and pass $active; each rail
    understands the keys it needs ('dashboard', 'records', 'consultations',
    'inventory' are common to all three).

    The default is the nurse's rail, not a refusal: a shared page opened by
    a role with no rail of its own still has to render something, and the
    nurse's is the full module list.
--}}
@php
    $railRole = (string) session('active_role', '');
    $railPartial = match ($railRole) {
        'clinic_staff' => 'partials.clinic-lusog-sidebar',
        'clinic_teacher' => 'partials.clinic-teacher-sidebar',
        default => 'partials.nurse-lusog-sidebar',
    };
@endphp
@include($railPartial, ['active' => $active ?? 'dashboard'])
