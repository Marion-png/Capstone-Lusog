{{--
    A Sulat-Pahibalo's answers as the paper carries them: the letter's own
    Cebuano wording in its own order, one row per box — each service with the
    learner's standing for it, each consent and allergy box ticked, not ticked
    or unclear as it is on the paper — and every handwritten line as it was
    written, inside the row of the box it belongs to. The printed words are
    never translated; only the section headings and the pills are the app's.
    $copy — App\Support\ConsentPaperCopy
--}}
@php
    use App\Models\HealthConsentForm;
    use App\Support\ConsentPaperCopy;
    use App\Support\ConsentStanding;

    // A box on the paper, as the pill beside its row: [class, words].
    $box = fn (string $state): array => match ($state) {
        ConsentPaperCopy::TICKED => ['ticked', 'Ticked'],
        ConsentPaperCopy::UNCLEAR => ['unclear', '?'],
        default => ['not_ticked', 'Not ticked'],
    };
    $service = fn (string $standing): array => [$standing, ConsentStanding::SERVICE_LABELS[$standing] ?? '?'];
@endphp

<div class="pcopy">
    <section class="pcopy-section">
        <h5 class="pcopy-head">
            <span class="pcopy-head-icon is-service" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg></span>
            Services
        </h5>
        <p class="pcopy-lead" lang="ceb">Ang mga serbisyong panglawas nga ihatag o ipahigayon mao ang mga musunod:</p>
        {{-- A heading that only groups sub-items (Pag Purga, Immunization)
             heads its rows, as it does on the paper. --}}
        <ul class="pcopy-rows">
            @foreach (HealthConsentForm::SERVICES as $key => $item)
                @if ($item['children'] === [])
                    @include('consent-forms._paper-copy-row', ['label' => $item['label'], 'pill' => $service($copy['standings'][$key]), 'line' => '', 'isService' => true])
                @else
                    <li class="pcopy-group">
                        <span class="pcopy-group-label" lang="ceb">{{ $item['label'] }}</span>
                        <ul class="pcopy-rows">
                            @foreach ($item['children'] as $childKey => $childLabel)
                                @include('consent-forms._paper-copy-row', ['label' => $childLabel, 'pill' => $service($copy['standings'][$childKey]), 'line' => '', 'isService' => true])
                            @endforeach
                        </ul>
                    </li>
                @endif
            @endforeach
        </ul>
    </section>

    <section class="pcopy-section">
        <h5 class="pcopy-head">
            <span class="pcopy-head-icon is-consent" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg></span>
            Parent's consent
        </h5>
        <p class="pcopy-title" lang="ceb">TIMAAN SA PAGDAWAT SA SULAT-PAHIBALO UG PAGTUGOT SA GINIKANAN/GUARDIAN</p>
        <p class="pcopy-lead" lang="ceb">(Palihug butang ug tsek (✓) sulod sa kahon)</p>
        <ul class="pcopy-rows">
            @foreach (HealthConsentForm::CONSENT_TEXT as $choice => $text)
                @php $lineKey = ConsentPaperCopy::CONSENT_LINES[$choice] ?? null; @endphp
                @include('consent-forms._paper-copy-row', [
                    'label' => $text,
                    'pill' => $box($copy['consent'][$choice]),
                    'line' => $lineKey !== null ? $copy['lines'][$lineKey] : '',
                    'isService' => false,
                ])
            @endforeach
        </ul>
    </section>

    {{-- The three kinds of allergy sit under the allergy box; the illness box
         is its sibling, not a fourth allergy. --}}
    <section class="pcopy-section">
        <h5 class="pcopy-head">
            <span class="pcopy-head-icon is-health" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.5-6 5 12 2.5-6H21"/></svg></span>
            Allergies and illnesses
        </h5>
        <ul class="pcopy-rows">
            @include('consent-forms._paper-copy-row', ['label' => HealthConsentForm::ALLERGY_TEXT['allergy'], 'pill' => $box($copy['boxes']['allergy']), 'line' => '', 'isService' => false])
            <li class="pcopy-group">
                <ul class="pcopy-rows">
                    @foreach (ConsentPaperCopy::ALLERGY_KINDS as $kind)
                        @include('consent-forms._paper-copy-row', [
                            'label' => HealthConsentForm::ALLERGY_TEXT[$kind],
                            'pill' => $box($copy['boxes'][$kind]),
                            'line' => $copy['lines'][$kind],
                            'isService' => false,
                        ])
                    @endforeach
                </ul>
            </li>
            @include('consent-forms._paper-copy-row', [
                'label' => HealthConsentForm::ALLERGY_TEXT['other_illness'],
                'pill' => $box($copy['boxes']['other_illness']),
                'line' => $copy['lines']['other_illness'],
                'isService' => false,
            ])
        </ul>
        <p class="pcopy-foot" lang="ceb">(Kon adunay Medical Certificate nga nagpamatuod sa kahimtang panglawas, palihug attach)</p>
    </section>
</div>
