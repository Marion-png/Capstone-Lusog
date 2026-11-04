{{--
    Opens the consultation dialog on one recorded visit. Drawn only for
    Consultation::EDIT_ROLES, which the endpoint checks again.

    Expects: $consultation, $consultationMedicines.
--}}
<button type="button" class="btn btn-secondary btn-row"
        data-bmodal-open="consultModal"
        data-consult-edit-id="{{ $consultation->id }}"
        data-consult-edit="{{ json_encode(\App\Support\ConsultationMedicines::forEditing($consultationMedicines ?? [], $consultation)) }}"
        aria-label="Edit consultation for {{ trim((string) $consultation->student_name) }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
    Edit
</button>
