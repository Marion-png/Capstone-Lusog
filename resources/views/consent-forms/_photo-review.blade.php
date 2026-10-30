<div class="pc-photo-review" id="{{ $reviewId }}" hidden>
    <section class="pc-photo-column" aria-label="Uploaded consent form">
        <div class="pc-review-heading">
            <h3>Uploaded form <span data-review-year></span></h3>
            <button type="button" class="btn btn-secondary" data-review-enlarge hidden>Enlarge</button>
        </div>
        <div class="pc-photo-frame" data-review-frame hidden>
            <img data-review-photo alt="Uploaded signed consent form">
        </div>
        <p class="pc-review-empty" data-review-empty>No signed form has been uploaded yet.</p>
        <a class="pc-strip-link" data-review-file target="_blank" rel="noopener" hidden>Open uploaded form</a>
    </section>
    <section class="pc-reading-column" aria-label="Consent form reading">
        <div class="pc-review-heading"><h3 data-review-title>Read from the uploaded photo</h3></div>
        <div class="pc-review-complete" data-review-complete role="status" hidden></div>
        <ul class="pc-review-warnings" data-review-warnings hidden></ul>
        <dl class="pc-review-facts pc-review-decision"><div><dt>Parent decision</dt><dd data-review-decision>Not recorded yet</dd></div></dl>
        @foreach ([
            'consented' => 'Consented services',
            'declined' => 'Not consented (excluded by parent)',
            'unclear' => 'Services to check',
        ] as $group => $heading)
            <div class="pc-service-group is-{{ $group }}" data-review-group="{{ $group }}" hidden>
                <div class="pc-review-heading"><h3>{{ $heading }}</h3><span data-review-count></span></div>
                <ul data-review-services></ul>
                @if ($group === 'declined')
                    <p class="pc-review-note">Do not give these services to this learner.</p>
                @endif
            </div>
        @endforeach
        <p class="pc-review-note" data-review-not-applicable hidden></p>
        <p class="pc-review-note" data-review-not-offered hidden></p>
        <dl class="pc-review-facts" data-review-facts hidden>
            <div data-review-exceptions-row hidden><dt>Exceptions written</dt><dd data-review-exceptions></dd></div>
            <div><dt>Reason given</dt><dd data-review-reason></dd></div>
            <div><dt>Allergies</dt><dd data-review-allergies></dd></div>
            <div><dt>Other illnesses</dt><dd data-review-illnesses></dd></div>
            <div><dt>Signatures</dt><dd data-review-signatures></dd></div>
        </dl>
        <p class="pc-review-note" data-review-unread>The detailed reading is not recorded. Check the uploaded form.</p>
    </section>
</div>
