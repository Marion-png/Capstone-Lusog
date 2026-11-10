{{--
    The confirmation an unusual measurement asks for (MeasurementPlausibility,
    testers' report #6): beyond ±4 SD of the WHO median but possible. Shown
    when the server refused the save for want of it, or when WhoGrowth.bind()
    finds the same thing as the figures are typed; its tick is then required.
    An impossible reading is never confirmable — it is refused on its field.

    Optional: $bag, the error bag the form's errors arrive in.
--}}
@php
    $mcErrors = isset($bag) ? $errors->getBag($bag) : $errors;
    $mcMessage = $mcErrors->first(\App\Support\MeasurementPlausibility::CONFIRM_FIELD);
@endphp
<div class="measure-confirm" data-measure-confirm role="alert" @if ($mcMessage === '') hidden @endif>
    <p class="measure-confirm-text" data-measure-confirm-text>{{ $mcMessage }}</p>
    <label class="measure-confirm-tick">
        <input type="checkbox" name="{{ \App\Support\MeasurementPlausibility::CONFIRM_FIELD }}" value="1">
        These measurements are correct
    </label>
</div>
