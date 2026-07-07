{{--
    The Sulat-Pahibalo document, shared by every role view.
    $form — HealthConsentForm
    $mode — 'adviser-edit' (service checkboxes live)
          | 'parent'       (consent section inputs live)
          | 'locked'       (fully read-only)
--}}
@php
    use App\Models\HealthConsentForm;
    $editServices = ($mode === 'adviser-edit');
    $editConsent  = ($mode === 'parent');
@endphp

<div class="doc">
    <div class="doc-header">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="School Seal" class="doc-seal">
        <div class="doc-rp">Republic of the Philippines</div>
        <div>Region XI</div>
        <div class="doc-form-title">SULAT-PAHIBALO</div>
    </div>

    <div class="doc-field"><label>DIVISION:</label><span class="doc-line">{{ $form->division }}</span></div>
    <div class="doc-field"><label>PANGALAN SA ISKWELAHAN:</label><span class="doc-line">{{ $form->school_name }}</span></div>
    <div class="doc-field"><label>ADDRESS SA ISKWELAHAN:</label><span class="doc-line">{{ $form->school_address }}</span></div>
    <div class="doc-field"><label>PETSA:</label><span class="doc-line">{{ optional($form->sent_at)->format('F j, Y') }}</span></div>
    <div class="doc-field"><label>PANGALAN SA ESTUDYANTE:</label><span class="doc-line">{{ $form->student_name }}</span></div>
    <div class="doc-field"><label>PINUY-ANAN SA ESTUDYANTE:</label><span class="doc-line">{{ $form->student_address }}</span></div>
    <div class="doc-field"><label>PANGALAN SA GINIKANAN / GUARDIAN:</label><span class="doc-line">{{ $form->parent_guardian_name }}</span></div>
    <div class="doc-salutation">Tinahud namong Ginikanan / Guardian:</div>

    <p class="doc-body">
        Ang atong pampublikong iskwelahan magpahigayon ug nagkadaiyang kalihukan ug serbisyong panglawas
        (health services) para sa mga kabataan-estudyante. Inubanan kini sa Department of Health (DOH) ug
        sa Lokal nga Panggamhanan / Local Government Unit (LGU).
    </p>
    <p class="doc-body">Ang mga serbisyong panglawas nga ihatag o ipahigayon mao ang mga musunod:</p>

    <div class="doc-services">
        @foreach (HealthConsentForm::SERVICES as $key => $service)
            <div class="doc-service">
                @if ($editServices)
                    <input type="checkbox" id="svc-{{ $key }}" name="services[]" value="{{ $key }}" @checked($form->hasService($key))>
                    <label for="svc-{{ $key }}">{{ $service['label'] }}</label>
                @else
                    <span class="doc-box">{!! $form->hasService($key) ? '&#10003;' : '' !!}</span>
                    <span>{{ $service['label'] }}</span>
                @endif
            </div>
            @foreach ($service['children'] as $childKey => $childLabel)
                <div class="doc-service doc-child">
                    @if ($editServices)
                        <input type="checkbox" id="svc-{{ $childKey }}" name="services[]" value="{{ $childKey }}" @checked($form->hasService($childKey))>
                        <label for="svc-{{ $childKey }}">{{ $childLabel }}</label>
                    @else
                        <span class="doc-box">{!! $form->hasService($childKey) ? '&#10003;' : '' !!}</span>
                        <span>{{ $childLabel }}</span>
                    @endif
                </div>
            @endforeach
        @endforeach
    </div>

    <p class="doc-body">
        Kining maong sulat-pahibalo gipadangat kaninyo aron sa pagpasayod sa mga aktibidades ug kalihukang
        panglawas nga ipahigayon dinhi sulod sa iskwelahan sa SY {{ $form->school_year }}. Kon aduna kamo
        pangutana o pagpaklaro kabahin niini, palihug ayaw pagduha-duha pag duol ug pakig istorya sa atong
        School Principal / School Head.
    </p>

    <p style="margin-top:14px;">Daghang Salamat.</p>

    <div class="doc-sign-block">
        <div class="doc-sign-inner">
            <div style="margin-bottom:26px;">Matinahuron,</div>
            <div class="doc-sign-name">{{ HealthConsentForm::PRINCIPAL_NAME }}</div>
            <div class="doc-sign-caption">(Pangalan ug Pirma Sa Principal / School Head)</div>
        </div>
    </div>

    <hr class="doc-divider">

    <div class="doc-consent-title">TIMAAN SA PAGDAWAT SA SULAT-PAHIBALO UG PAGTUGOT SA GINIKANAN/GUARDIAN</div>

    <p class="doc-body">
        Kini nagpamatuod nga akong nadawat ug nabasa ang mga detalye niining Sulat-Pahibalo mahitungod sa
        serbisyong panglawas nga mahimong madawat sa akong anak.
    </p>
    <p style="text-align:center; font-style:italic; font-weight:700;">(Palihug butang ug tsek (&#10003;) sulod sa kahon)</p>

    <div class="doc-consent-options">
        {{-- Option 1: consent to all --}}
        <div class="doc-consent-option">
            @if ($editConsent)
                <input type="radio" id="consent-all" name="consent_choice" value="all" @checked(old('consent_choice', $form->consent_choice) === 'all')>
                <label for="consent-all">
            @else
                <span class="doc-box">{!! $form->consent_choice === 'all' ? '&#10003;' : '' !!}</span>
                <span>
            @endif
                Oo, ako mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong
                panglawas nga ihatag sa iskwelahan base sa rekomendasyon sa DOH
            @if ($editConsent) </label> @else </span> @endif
        </div>

        {{-- Option 2: consent with exceptions / specific services --}}
        <div class="doc-consent-option">
            @if ($editConsent)
                <input type="radio" id="consent-specific" name="consent_choice" value="specific" @checked(old('consent_choice', $form->consent_choice) === 'specific')>
                <label for="consent-specific" style="flex:1;">
                    Oo, ako mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong
                    panglawas, gawas lamang niini (palihug isulat):
                    <input type="text" name="consent_exceptions" class="doc-writein" value="{{ old('consent_exceptions', $form->consent_exceptions) }}" placeholder="">
                </label>
            @else
                <span class="doc-box">{!! $form->consent_choice === 'specific' ? '&#10003;' : '' !!}</span>
                <span style="flex:1;">
                    Oo, ako mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong
                    panglawas, gawas lamang niini (palihug isulat):
                    <span class="doc-line" style="display:block;">{{ $form->consent_exceptions }}</span>
                </span>
            @endif
        </div>

        {{-- Option 3: refuse, with reason --}}
        <div class="doc-consent-option">
            @if ($editConsent)
                <input type="radio" id="consent-deny" name="consent_choice" value="deny" @checked(old('consent_choice', $form->consent_choice) === 'deny')>
                <label for="consent-deny" style="flex:1;">
                    Dili ko mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong
                    panglawas, tungod niining mosunod nga rason (palihug isulat):
                    <input type="text" name="refusal_reason" class="doc-writein" value="{{ old('refusal_reason', $form->refusal_reason) }}" placeholder="">
                </label>
            @else
                <span class="doc-box">{!! $form->consent_choice === 'deny' ? '&#10003;' : '' !!}</span>
                <span style="flex:1;">
                    Dili ko mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong
                    panglawas, tungod niining mosunod nga rason (palihug isulat):
                    <span class="doc-line" style="display:block;">{{ $form->refusal_reason }}</span>
                </span>
            @endif
        </div>
    </div>

    <p class="doc-allergy-note">Kon adunay Allergy ang bata, palihug butang ug tsek (&#10003;) sa kahon kon unsang klase:</p>

    @php
        $allergyFields = [
            'allergy_food' => 'Pagkaon/Food (isulat unsang klaseng pagkaon)',
            'allergy_medicine' => 'Tambal/Medicines (isulat unsang klaseng tambal)',
            'prev_immunization' => 'Nahatag nga Bakuna/Previous Immunization (isulat unsang klaseng bakuna)',
            'other_illness' => 'Kasamtangang Sakit or Gibati / Other Illnesses',
        ];
    @endphp
    <div class="doc-consent-options">
        @foreach ($allergyFields as $field => $label)
            <div class="doc-consent-option">
                @if ($editConsent)
                    <span class="doc-box">{!! old($field, $form->$field) ? '&#10003;' : '' !!}</span>
                    <label style="flex:1;">
                        {{ $label }}
                        <input type="text" name="{{ $field }}" class="doc-writein" value="{{ old($field, $form->$field) }}">
                    </label>
                @else
                    <span class="doc-box">{!! $form->$field ? '&#10003;' : '' !!}</span>
                    <span style="flex:1;">
                        {{ $label }}
                        <span class="doc-line" style="display:block;">{{ $form->$field }}</span>
                    </span>
                @endif
            </div>
        @endforeach
    </div>
    <p style="font-style:italic; font-size:.85rem;">(Kon adunay Medical Certificate nga nagpamatuod sa kahimtang panglawas, palihug attach)</p>

    {{-- Parent signature area --}}
    <div class="doc-sign-block">
        <div class="doc-sign-inner">
            @if ($editConsent)
                <div class="sig-pad-wrap no-print">
                    <canvas id="sigPad" width="600" height="160"></canvas>
                    <div class="sig-pad-hint" id="sigHint">Draw your signature here (mouse, finger, or stylus)</div>
                </div>
                <div class="sig-pad-tools no-print">
                    <button type="button" class="cf-btn cf-btn-ghost" id="sigClear">Clear Signature</button>
                </div>
                <input type="hidden" name="signature" id="sigData">
            @elseif ($form->signature)
                <img src="{{ $form->signature }}" alt="Parent signature" class="doc-signature-img">
            @else
                <div style="height:60px;"></div>
            @endif
            <div class="doc-sign-name" style="margin-top:6px;">{{ $form->parent_guardian_name }}</div>
            <div class="doc-sign-caption">(Pangalan ug Pirma Sa Ginikanan o Guardian)</div>
            @if ($form->signed_at)
                <div style="font-size:.78rem; margin-top:4px;">Signed: {{ $form->signed_at->format('F j, Y g:i A') }}</div>
            @endif
        </div>
    </div>
</div>
