{{-- Said only where it is true: a copy of the app whose every learner and
     form is made up (config app.simulated_data, off by default). --}}
@if ($simulatedData ?? false)
    <div class="cf-flash cf-flash-info cf-sim-banner" role="note">
        <b>Testing environment.</b> Every learner record and consent form on this page is simulated, with fictitious names and signatures.
    </div>
@endif
