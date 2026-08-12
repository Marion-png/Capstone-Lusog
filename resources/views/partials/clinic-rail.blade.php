{{--
    Renders whichever clinic rail belongs to the signed-in role.

    Health Records, Consultation Log and Medicine Inventory are shared by
    the School Nurse and Clinic Staff. They used to include the nurse rail
    unconditionally, so a Clinic Staff session that clicked Consultation Log
    landed on a page wearing the nurse's navigation — Review Queue, health
    programmes, Dispensing Log — none of which it may use. It read as being
    thrown onto "the nurse's side".

    Include this instead of a rail partial and pass $active; each rail
    understands the keys it needs ('dashboard', 'records', 'consultations',
    'inventory' are common to both).
--}}
@php
    $railRole = (string) session('active_role', '');
    $railPartial = $railRole === 'clinic_staff'
        ? 'partials.clinic-lusog-sidebar'
        : 'partials.nurse-lusog-sidebar';
@endphp
@include($railPartial, ['active' => $active ?? 'dashboard'])
