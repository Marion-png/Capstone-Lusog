<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Student Profile - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'students'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Student Profile'])
    <div class="content">
        @php
            $middle = trim((string) ($prototypeRecord['middle_name'] ?? ''));
            $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
            $fullName = trim(($prototypeRecord['last_name'] ?? '') . ', ' . ($prototypeRecord['first_name'] ?? '') . $middleInitial);
            $lrn = (string) ($prototypeRecord['lrn'] ?? '');
        @endphp

        <div class="student-profile-topline">
            <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="student-profile-back" aria-label="Back to My Students">&larr;</a>
            <div>
                <div class="student-profile-titleline">Student Profile</div>
                <div class="student-profile-subline">Health history at a glance &mdash; use Edit Profile to make changes</div>
            </div>
        </div>

        <div class="sp-readonly-banner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span><b>Read-only:</b> Clinic notes, consultations, and documents below are recorded by the School Nurse/Clinic Staff. You can view them but cannot add or edit them.</span>
        </div>

        <div class="card student-profile-page">
            <div class="sp-cover"></div>

            <div class="sp-identity">
                <div class="sp-avatar" id="vpInitials">&ndash;</div>
                <div class="sp-identity-body">
                    <div class="sp-name" id="vpName">-</div>
                    <div class="sp-class" id="vpGradeSection">-</div>
                    <div class="sp-meta">
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8.5" cy="10" r="2"/><path d="M5 17c.7-1.7 2-2.5 3.5-2.5S11.3 15.3 12 17"/><line x1="15" y1="9" x2="19" y2="9"/><line x1="15" y1="13" x2="19" y2="13"/></svg>LRN: <b id="vpLrn">-</b></span>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="14" r="5"/><path d="M19 5l-5.4 5.4M15 5h4v4"/></svg>Sex: <b id="vpGender">-</b></span>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Age: <b id="vpAge">-</b></span>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>DOB: <b id="vpDob">-</b></span>
                    </div>
                </div>
                <div class="sp-identity-actions">
                    <button type="button" class="btn btn-secondary" id="vpPrint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Print
                    </button>
                    <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved', 'edit' => $lrn]) }}" class="btn" id="vpEditProfile">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                        Edit Profile
                    </a>
                </div>
            </div>

            <div class="sp-tabs" role="tablist" aria-label="Student profile sections">
                <button type="button" class="sp-tab active" role="tab" aria-selected="true" data-panel="vpTabSheet1">Sheet 1 <span class="sp-tab-badge">Learner Info</span></button>
                <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="vpTabSheet2">Sheet 2 <span class="sp-tab-badge">Systems Review</span></button>
                <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="vpTabConsent">Consent <span class="sp-tab-badge" id="vpConsentTabBadge">&ndash;</span></button>
                <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="vpTabFeeding">Feeding Status <span class="sp-tab-badge" id="vpFeedingTabBadge">&ndash;</span></button>
                <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="vpTabDocuments">Medical Documents <span class="sp-tab-badge" id="vpDocumentsTabBadge">0</span></button>
                <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="vpTabConsultations">Consultation Log <span class="sp-tab-badge" id="vpConsultationsTabBadge">0</span></button>
            </div>

            <div class="student-profile-body">
            <div class="sp-panel active" id="vpTabSheet1" role="tabpanel">
                {{-- Sheet 1 mirrors the enrolment form's Sheet 1 exactly: learner
                     information, parent/guardian, and baseline health data. Nothing
                     from Sheet 2 or from another role's records belongs here. --}}
                <section class="student-profile-section">
                    <h4>Learner Information</h4>
                    <div class="student-profile-grid">
                        <div><span>Name of Learner:</span><b id="vpS1Name">-</b></div>
                        <div><span>LRN:</span><b id="vpS1Lrn">-</b></div>
                        <div><span>Date of Birth:</span><b id="vpS1Dob">-</b></div>
                        <div><span>Birthplace:</span><b id="vpBirthplace">-</b></div>
                        <div><span>Sex:</span><b id="vpS1Sex">-</b></div>
                        <div><span>Grade &amp; Section:</span><b id="vpS1GradeSection">-</b></div>
                    </div>
                </section>

                <section class="student-profile-section">
                    <h4>Parent/Guardian</h4>
                    <div class="student-profile-grid">
                        <div><span>Name:</span><b id="vpGuardian">-</b></div>
                        <div><span>Contact Number:</span><b id="vpContact">-</b></div>
                        <div class="full"><span>Address:</span><b id="vpAddress">-</b></div>
                    </div>
                </section>

                <section class="student-profile-section">
                    <h4>Medical &amp; Family History</h4>
                    <div id="vpHealthHistory"></div>
                </section>

                <section class="student-profile-section">
                    <h4>General Appearance</h4>
                    <div class="student-profile-grid metrics">
                        <div><span>Level of Consciousness:</span><b id="vpConsciousness">-</b></div>
                        <div><span>Posture / Gait:</span><b id="vpPosture">-</b></div>
                        <div><span>Hygiene / Grooming:</span><b id="vpHygiene">-</b></div>
                    </div>
                </section>

                <section class="student-profile-section">
                    <h4>Vital Signs</h4>
                    <div class="student-profile-grid metrics">
                        <div><span>Weight:</span><b id="vpWeight">-</b></div>
                        <div><span>Height:</span><b id="vpHeight">-</b></div>
                        <div><span>(Height)<sup>2</sup>:</span><b id="vpHeightSquared">-</b></div>
                        <div><span>BMI:</span><b id="vpBmi">-</b></div>
                        <div><span>Temperature:</span><b id="vpTemperature">-</b></div>
                        <div><span>Pulse:</span><b id="vpPulse">-</b></div>
                        <div><span>Blood Pressure:</span><b id="vpBloodPressure">-</b></div>
                        <div><span>Nutritional Status:</span><b id="vpNutri">-</b></div>
                        <div><span>Height-for-Age:</span><b id="vpHfa">-</b></div>
                    </div>
                </section>

            </div>{{-- end Sheet 1 panel --}}

            <div class="sp-panel" id="vpTabSheet2" role="tabpanel">
                <section class="student-profile-section">
                    <h4>Systems Review</h4>
                    <div id="vpSystemsReview"></div>
                </section>
            </div>

            <div class="sp-panel" id="vpTabConsent" role="tabpanel">
                <section class="student-profile-section">
                    <h4>Parent's Consent</h4>
                    <div class="student-profile-grid">
                        <div><span>Status:</span><b id="vpConsentStatus">-</b></div>
                        <div><span>Response:</span><b id="vpConsentChoice">-</b></div>
                        <div><span>Signed by guardian:</span><b id="vpConsentSigned">-</b></div>
                        <div><span>Reviewed by adviser:</span><b id="vpConsentReviewed">-</b></div>
                    </div>
                    <div class="sp-note">Open the Consent Forms page to send, review, or print this learner's Sulat-Pahibalo.</div>
                </section>
            </div>

            <div class="sp-panel" id="vpTabFeeding" role="tabpanel">
                <section class="student-profile-section">
                    <h4>Feeding Status</h4>
                    <div class="student-profile-grid metrics">
                        <div><span>Baseline nutritional status:</span><b id="vpFeedBaseline">-</b></div>
                        <div><span>Endline nutritional status:</span><b id="vpFeedEndline">-</b></div>
                        <div><span>Attendance sessions:</span><b id="vpFeedSessions">-</b></div>
                    </div>
                    <div class="sp-note">At-risk is derived from feeding attendance and is maintained by the Feeding Coordinator.</div>
                </section>
            </div>

            <div class="sp-panel" id="vpTabDocuments" role="tabpanel">
                <section class="student-profile-section">
                    <h4>Medical Documents</h4>
                    <div id="vpDocumentsList"></div>
                    <div class="sp-note">Uploaded by the School Nurse/Clinic Staff when a medical certificate is attached to a health condition. Downloading is restricted to Clinic Staff/School Nurse.</div>
                </section>
            </div>

            <div class="sp-panel" id="vpTabConsultations" role="tabpanel">
                <section class="student-profile-section">
                    <h4>Consultation Log</h4>
                    <div id="vpConsultationsList"></div>
                    <div class="sp-note">Matched by learner name &mdash; the clinic's consultation log does not record an LRN, so entries for very similar names could occasionally be mismatched.</div>
                </section>
            </div>
            </div>
        </div>
    </div>
</div>

<script>
const STUDENT_PROFILE_RECORD = @json($prototypeRecord);
const STUDENT_PROFILE_META = @json($meta);

(() => {
    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value && String(value).trim() !== '' ? String(value) : '-';
        }
    };

    const toNumber = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const computeBmi = (heightCm, weightKg) => {
        const height = toNumber(heightCm);
        const weight = toNumber(weightKg);
        if (!height || !weight || height <= 0 || weight <= 0) {
            return null;
        }

        const meters = height / 100;
        return weight / (meters * meters);
    };

    // ── Sheet 2 read-only rendering ────────────────────────────────
    const SYSTEMS_REVIEW_GROUPS = [
        ['Skin / Integumentary', [['skin_normal', 'Normal'], ['skin_lesions', 'Lesions / Rashes'], ['skin_pallor', 'Pallor']]],
        ['HEENT', [['heent_normal', 'Normal'], ['heent_abnormal', 'Abnormal']]],
        ['Respiratory', [['resp_clear', 'Clear breath sounds'], ['resp_cough', 'Cough']]],
        ['Cardiovascular', [['cardio_regular', 'Regular rhythm'], ['cardio_irregular', 'Irregular']]],
        ['Abdomen / GI', [['abdo_soft', 'Soft, non-tender'], ['abdo_pain', 'Pain']]],
        ['Neurologic', [['neuro_alert', 'Alert, oriented'], ['neuro_reflexes', 'Reflexes normal'], ['neuro_abnormal', 'Abnormal']]],
        ['Dental', [['dental_good', 'Good'], ['dental_fair', 'Fair'], ['dental_poor', 'Poor'], ['dental_caries', 'Dental caries'], ['dental_gum', 'Gum inflammation'], ['dental_referral', 'Referral to dentist']]],
        ['Immunization', [['immun_complete', 'Complete'], ['immun_incomplete', 'Incomplete'], ['immun_not_available', 'Not available']]],
    ];

    const SYSTEMS_REVIEW_TEXT = [
        ['right_eye', 'Right Eye'], ['left_eye', 'Left Eye'], ['immun_date', 'Date Record Reviewed'],
        ['notes', 'Notes / Details'], ['summary', 'Summary of Findings'],
        ['recommendations', 'Recommendations / Referrals'],
        ['examiner_name', 'Examiner Name'], ['examiner_date', 'Date'],
    ];

    // Built with DOM nodes rather than innerHTML: every value here is
    // adviser-typed free text.
    const renderSystemsReview = (review) => {
        const host = document.getElementById('vpSystemsReview');
        if (!host) {
            return;
        }

        host.textContent = '';

        if (!review || typeof review !== 'object' || Object.keys(review).length === 0) {
            const empty = document.createElement('p');
            empty.className = 'sp-note';
            empty.textContent = 'No systems review was recorded for this learner.';
            host.appendChild(empty);
            return;
        }

        SYSTEMS_REVIEW_GROUPS.forEach(([title, items]) => {
            const block = document.createElement('div');
            block.className = 'sp-review-block';

            const heading = document.createElement('span');
            heading.className = 'sp-review-title';
            heading.textContent = title;
            block.appendChild(heading);

            const list = document.createElement('div');
            list.className = 'sp-review-items';
            items.forEach(([key, label]) => {
                const on = Boolean(review[key]);
                const item = document.createElement('span');
                item.className = on ? 'sp-review-item on' : 'sp-review-item';
                item.textContent = (on ? '✓ ' : '– ') + label;
                list.appendChild(item);
            });
            block.appendChild(list);
            host.appendChild(block);
        });

        const notes = SYSTEMS_REVIEW_TEXT.filter(([key]) => {
            const value = review[key];
            return value !== null && value !== undefined && String(value).trim() !== '';
        });

        // The roster carries a flag rather than the signature image itself.
        if (review.examiner_signature_present) {
            notes.push(['examiner_signature_present', 'Examiner Signature']);
            review.examiner_signature_present = 'Signed';
        }

        if (notes.length) {
            const grid = document.createElement('div');
            grid.className = 'student-profile-grid';
            grid.style.marginTop = '12px';
            notes.forEach(([key, label]) => {
                const cell = document.createElement('div');
                if (['notes', 'summary', 'recommendations'].includes(key)) {
                    cell.className = 'full';
                }
                const k = document.createElement('span');
                k.textContent = label + ':';
                const v = document.createElement('b');
                v.textContent = String(review[key]);
                cell.append(k, v);
                grid.appendChild(cell);
            });
            host.appendChild(grid);
        }
    };

    const HEALTH_HISTORY_GROUPS = [
        ['Medical History', [['med_asthma', 'Asthma'], ['med_diabetes', 'Diabetes'], ['med_seizure', 'Seizure Disorder'], ['med_infections', 'Frequent Infections'], ['med_heart', 'Heart Condition'], ['med_tuberculosis', 'Tuberculosis'], ['med_allergies', 'Allergies'], ['med_hospitalization', 'Hospitalization / Surgery']]],
        ['Family History', [['fam_hypertension', 'Hypertension'], ['fam_diabetes', 'Diabetes'], ['fam_heart', 'Heart Disease'], ['fam_cancer', 'Cancer'], ['fam_mental', 'Mental Health Conditions']]],
    ];

    const HEALTH_HISTORY_TEXT = [
        ['allergies_detail', 'Allergy details'], ['hospitalization_detail', 'Hospitalization / Surgery'],
        ['current_medications', 'Current Medications'], ['other_conditions', 'Other Conditions'],
        ['genetic_disorders', 'Genetic / Hereditary Disorders'],
    ];

    const renderHealthHistory = (history) => {
        const host = document.getElementById('vpHealthHistory');
        if (!host) {
            return;
        }

        host.textContent = '';

        if (!history || typeof history !== 'object' || Object.keys(history).length === 0) {
            const empty = document.createElement('p');
            empty.className = 'sp-note';
            empty.textContent = 'No medical or family history was recorded for this learner.';
            host.appendChild(empty);
            return;
        }

        HEALTH_HISTORY_GROUPS.forEach(([title, items]) => {
            const block = document.createElement('div');
            block.className = 'sp-review-block';

            const heading = document.createElement('span');
            heading.className = 'sp-review-title';
            heading.textContent = title;
            block.appendChild(heading);

            const list = document.createElement('div');
            list.className = 'sp-review-items';
            items.forEach(([key, label]) => {
                const on = Boolean(history[key]);
                const item = document.createElement('span');
                item.className = on ? 'sp-review-item on' : 'sp-review-item';
                item.textContent = (on ? '✓ ' : '– ') + label;
                list.appendChild(item);
            });
            block.appendChild(list);
            host.appendChild(block);
        });

        const notes = HEALTH_HISTORY_TEXT.filter(([key]) => {
            const value = history[key];
            return value !== null && value !== undefined && String(value).trim() !== '';
        });

        if (notes.length) {
            const grid = document.createElement('div');
            grid.className = 'student-profile-grid';
            grid.style.marginTop = '12px';
            notes.forEach(([key, label]) => {
                const cell = document.createElement('div');
                const k = document.createElement('span');
                k.textContent = label + ':';
                const v = document.createElement('b');
                v.textContent = String(history[key]);
                cell.append(k, v);
                grid.appendChild(cell);
            });
            host.appendChild(grid);
        }
    };

    const renderDocumentsList = (documents) => {
        const host = document.getElementById('vpDocumentsList');
        if (!host) {
            return;
        }
        host.textContent = '';
        const list = Array.isArray(documents) ? documents : [];
        setText('vpDocumentsTabBadge', String(list.length));

        if (!list.length) {
            const empty = document.createElement('p');
            empty.className = 'sp-note';
            empty.textContent = 'No medical documents on file for this learner.';
            host.appendChild(empty);
            return;
        }

        list.forEach((doc) => {
            const row = document.createElement('div');
            row.className = 'sp-list-row';

            const title = document.createElement('div');
            title.className = 'sp-list-row-title';
            title.textContent = doc.file_name || 'Untitled document';
            row.appendChild(title);

            const meta = document.createElement('div');
            meta.className = 'sp-list-row-meta';
            [
                ['Condition', doc.condition_name],
                ['Doctor/Clinic', doc.doctor_clinic],
                ['Diagnosis date', doc.diagnosis_date],
                ['Uploaded by', doc.uploaded_by],
            ].forEach(([label, value]) => {
                const span = document.createElement('span');
                span.textContent = `${label}: ${value || '-'}`;
                meta.appendChild(span);
            });
            row.appendChild(meta);

            host.appendChild(row);
        });
    };

    const renderConsultationsList = (consultations) => {
        const host = document.getElementById('vpConsultationsList');
        if (!host) {
            return;
        }
        host.textContent = '';
        const list = Array.isArray(consultations) ? consultations : [];
        setText('vpConsultationsTabBadge', String(list.length));

        if (!list.length) {
            const empty = document.createElement('p');
            empty.className = 'sp-note';
            empty.textContent = 'No consultation records matched to this learner\'s name yet.';
            host.appendChild(empty);
            return;
        }

        list.forEach((visit) => {
            const row = document.createElement('div');
            row.className = 'sp-list-row';

            const title = document.createElement('div');
            title.className = 'sp-list-row-title';
            title.textContent = visit.condition || 'Consultation';
            row.appendChild(title);

            const meta = document.createElement('div');
            meta.className = 'sp-list-row-meta';
            [
                ['Date', visit.consulted_at],
                ['Treatment given', visit.treatment_given],
                ['Status', visit.status],
            ].forEach(([label, value]) => {
                const span = document.createElement('span');
                span.textContent = `${label}: ${value || '-'}`;
                meta.appendChild(span);
            });
            row.appendChild(meta);

            host.appendChild(row);
        });
    };

    const renderProfile = (record, meta = {}) => {
        const fullName = [record.last_name, ',', record.first_name, record.middle_name ? (' ' + String(record.middle_name).charAt(0).toUpperCase() + '.') : '']
            .join(' ')
            .replace(' ,', ',')
            .replace(/\s+/g, ' ')
            .trim();
        const dob = [record.birth_year, record.birth_month, record.birth_day].filter(Boolean).join('-');
        const heightCm = toNumber(record.height_cm);
        const weightKg = toNumber(record.weight_kg);
        const bmi = record.bmi_value ? Number(record.bmi_value) : computeBmi(heightCm, weightKg);
        const heightM = heightCm ? (heightCm / 100) : null;
        const heightSquared = heightM ? (heightM * heightM) : null;
        const gradeSection = [record.grade_level, record.section].filter(Boolean).join(' - ') || '-';

        const initials = `${record.first_name || ''}${record.last_name || ''}`.trim()
            ? ((record.first_name || ' ').charAt(0) + (record.last_name || ' ').charAt(0)).toUpperCase()
            : '--';

        setText('vpInitials', initials);
        setText('vpName', fullName || '-');
        setText('vpLrn', record.lrn || '-');
        setText('vpAge', record.age || '-');
        setText('vpDob', dob || '-');
        setText('vpBirthplace', record.birthplace || '-');
        setText('vpGender', record.gender || '-');
        setText('vpGradeSection', gradeSection);

        // Sheet 1 renders the learner-information block in full, the same way
        // the enrolment form's Sheet 1 does.
        setText('vpS1Name', fullName || '-');
        setText('vpS1Lrn', record.lrn || '-');
        setText('vpS1Dob', dob || '-');
        setText('vpS1Sex', record.gender || '-');
        setText('vpS1GradeSection', gradeSection);

        renderSystemsReview(record.systems_review);
        renderHealthHistory(record.health_history);

        const history = record.health_history && typeof record.health_history === 'object'
            ? record.health_history
            : {};
        const consciousness = history.consciousness === 'Other' && history.consciousness_other
            ? `Other — ${history.consciousness_other}`
            : history.consciousness;
        const posture = history.posture === 'Abnormal' && history.posture_detail
            ? `Abnormal — ${history.posture_detail}`
            : history.posture;

        setText('vpConsciousness', consciousness || '-');
        setText('vpPosture', posture || '-');
        setText('vpHygiene', history.hygiene || '-');

        setText('vpTemperature', record.temperature_c ? `${record.temperature_c} °C` : '-');
        setText('vpPulse', record.pulse_bpm ? `${record.pulse_bpm} bpm` : '-');
        setText('vpBloodPressure', record.blood_pressure || '-');

        const consent = meta.consent_detail || {};
        const consentLabels = { approved: 'Approved', partial: 'Partial', declined: 'Declined', pending: 'Pending' };
        setText('vpConsentTabBadge', consentLabels[meta.consent] || 'Pending');
        setText('vpConsentStatus', consent.status || 'Not started');
        setText('vpConsentChoice', consent.choice || 'Awaiting guardian response');
        setText('vpConsentSigned', consent.signed_at || '-');
        setText('vpConsentReviewed', consent.reviewed_at || '-');

        const feeding = meta.feeding || {};
        setText('vpFeedingTabBadge', meta.at_risk ? 'At risk' : 'Not flagged');
        setText('vpFeedBaseline', feeding.baseline_status || record.nutritional_status_bmi_for_age || '-');
        setText('vpFeedEndline', feeding.endline_status || '-');
        setText('vpFeedSessions', feeding.sessions ? String(feeding.sessions) : '0');

        renderDocumentsList(meta.documents);
        renderConsultationsList(meta.consultations);

        setText('vpGuardian', record.parent_guardian || '-');
        setText('vpContact', record.telephone_no || '-');
        setText('vpAddress', record.address || '-');

        setText('vpWeight', weightKg ? `${weightKg} kg` : '-');
        setText('vpHeight', heightM ? `${heightM.toFixed(2)} m` : '-');
        setText('vpHeightSquared', heightSquared ? heightSquared.toFixed(4) : '-');
        setText('vpBmi', bmi ? bmi.toFixed(1) : '-');
        setText('vpNutri', record.nutritional_status_bmi_for_age || '-');
        setText('vpHfa', record.nutritional_status_height_for_age || '-');
    };

    // ── Profile tabs ───────────────────────────────────────────────
    const showProfileTab = (panelId) => {
        document.querySelectorAll('.sp-panel').forEach((panel) => {
            panel.classList.toggle('active', panel.id === panelId);
        });
        document.querySelectorAll('.sp-tab').forEach((tab) => {
            const selected = tab.dataset.panel === panelId;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    };

    document.querySelectorAll('.sp-tab').forEach((tab) => {
        tab.addEventListener('click', () => showProfileTab(tab.dataset.panel));
    });

    document.getElementById('vpPrint')?.addEventListener('click', () => window.print());

    renderProfile(STUDENT_PROFILE_RECORD, STUDENT_PROFILE_META);
})();
</script>
@include('partials.sidebar-hover-pin')
</body>
</html>
