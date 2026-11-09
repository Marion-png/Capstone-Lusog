{{--
    The BMI report's signature block, one per population the Learners control
    offers, and the page shows the one it is set to ([data-foot-for]).

    All Learners is the school's nutritional assessment: the clinic prepares
    it, the MAPEH Department Head attests it, the Principal notes it. Only
    Beneficiaries is the feeding programme's own report, so the Feeding
    Coordinator prepares it, the School Clinic Nurse/Teacher attests it and
    the Principal II notes it.

    $prefix — the panel's field prefix (bmib, bmif).
--}}
@php
    $headDesignation = \App\Support\SchoolSignatories::HEAD_DESIGNATION;
@endphp
<div class="foot-grid cols-3" data-foot-for="all">
    <div class="foot-block">
        <div class="foot-label">Prepared by:</div>
        <input type="text" class="sign-input" data-field="{{ $prefix }}_prepared_name" value="{{ $nurseName ?? '' }}" placeholder="Full name" aria-label="Prepared by name">
        <input type="text" class="foot-role-input" data-field="{{ $prefix }}_prepared_role" placeholder="School Clinic Nurse / Teacher" aria-label="Prepared by designation">
    </div>
    <div class="foot-block">
        <div class="foot-label">Attested by:</div>
        <input type="text" class="sign-input" data-field="{{ $prefix }}_attested_name" placeholder="Full name" aria-label="Attested by name">
        <input type="text" class="foot-role-input" data-field="{{ $prefix }}_attested_role" placeholder="MAPEH Department Head" aria-label="Attested by designation">
    </div>
    <div class="foot-block">
        <div class="foot-label">Noted by:</div>
        <input type="text" class="sign-input" data-field="{{ $prefix }}_noted_name" placeholder="Full name" aria-label="Noted by name">
        <input type="text" class="foot-role-input" data-field="{{ $prefix }}_noted_role" placeholder="{{ $headDesignation }}" aria-label="Noted by designation">
    </div>
</div>
<div class="foot-grid cols-3" data-foot-for="beneficiaries" hidden>
    <div class="foot-block">
        <div class="foot-label">Prepared by:</div>
        <input type="text" class="sign-input" data-field="{{ $prefix }}_ben_prepared_name" value="{{ $coordinatorName ?? '' }}" placeholder="Full name" aria-label="Prepared by name">
        <div class="foot-role">Feeding Coordinator</div>
    </div>
    <div class="foot-block">
        <div class="foot-label">Attested by:</div>
        <input type="text" class="sign-input" data-field="{{ $prefix }}_ben_attested_name" value="{{ $nurseName ?? '' }}" placeholder="Full name" aria-label="Attested by name">
        <div class="foot-role">School Clinic Nurse/Teacher</div>
    </div>
    <div class="foot-block">
        <div class="foot-label">Noted by:</div>
        <input type="text" class="sign-input" data-field="{{ $prefix }}_ben_noted_name" value="{{ $headName ?? '' }}" placeholder="Full name" aria-label="Noted by name">
        <div class="foot-role">{{ $headDesignation }}</div>
    </div>
</div>
