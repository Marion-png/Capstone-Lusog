<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#14532D">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Terms of Service - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --ink:        var(--lg-ink, #1F2D25);
            --ink-soft:   var(--lg-ink-soft, #6B7C72);
            --line:       var(--lg-border, #DCE8E0);
            --card:       var(--lg-card, #FFFFFF);
            --page:       var(--lg-page, #F6F9F7);
            --brand:      var(--lg-emerald, #1F8A4C);
            --brand-deep: var(--lg-emerald-deep, #126B3A);
            --mint:       var(--lg-mint, #E7F5EC);
            --r-card:     var(--lg-r-card, 12px);
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            background: var(--page);
            color: var(--ink);
            padding: 40px 24px 56px;
        }

        .doc {
            width: min(860px, 100%);
            margin: 0 auto;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r-card);
            overflow: hidden;
        }

        .doc-head {
            position: relative;
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 28px 34px;
            color: #fff;
            background: var(--brand-deep);
        }
        .doc-head img { width: 54px; height: auto; flex: none; }
        .doc-head h1 {
            font-family: 'DM Serif Display', Georgia, serif;
            font-weight: 400;
            font-size: 1.9rem;
            line-height: 1.15;
        }
        .doc-head p { margin-top: 4px; font-size: .84rem; color: rgba(236, 251, 243, .88); }

        .doc-body { padding: 30px 34px 36px; }

        .doc-intro {
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--lg-rail, #EEF4F0);
            font-size: .9rem;
            line-height: 1.65;
        }

        .toc { margin: 22px 0 6px; padding-left: 20px; columns: 2; column-gap: 32px; font-size: .86rem; line-height: 1.9; }
        .toc a { color: var(--brand-deep); font-weight: 600; text-decoration: none; }
        .toc a:hover { color: var(--brand); }

        section { margin-top: 28px; scroll-margin-top: 16px; }
        h2 {
            font-family: 'DM Serif Display', Georgia, serif;
            font-weight: 400;
            font-size: 1.32rem;
            color: var(--ink);
            margin-bottom: 10px;
        }
        h2 span { color: var(--brand); font-style: italic; margin-right: 6px; }
        section p, section li { font-size: .92rem; line-height: 1.7; color: var(--ink); }
        section p + p { margin-top: 10px; }
        section ul { margin: 8px 0 0 20px; display: grid; gap: 6px; }
        strong { font-weight: 600; }

        .doc-foot {
            margin-top: 34px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: .82rem;
            color: var(--ink-soft);
        }
        .doc-foot a {
            color: var(--brand-deep);
            font-weight: 600;
            text-decoration: none;
            border-radius: 6px;
            padding: 8px 10px;
        }
        .doc-foot a:hover { background: var(--mint); }

        @media (max-width: 640px) {
            body { padding: 0; }
            .doc { border: none; border-radius: 0; }
            .doc-head, .doc-body { padding-left: 20px; padding-right: 20px; }
            .doc-head h1 { font-size: 1.55rem; }
            .toc { columns: 1; }
        }

        @media print {
            html, body { height: auto; overflow: visible; background: #fff; padding: 0; }
            .doc { border: none; }
            .doc-head { color: #000; background: none; border-bottom: 1px solid #000; }
            .doc-head p { color: #000; }
            .toc, .doc-foot a { display: none; }
        }
    </style>
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
    @include('partials.lusog-skin')
    <style>
        @media screen {
            html .doc {
                background: var(--sk-card);
                border-color: var(--sk-line);
                box-shadow: 0 2px 4px rgba(31, 41, 51, .05), 0 22px 50px rgba(20, 83, 45, .12);
            }
            html .doc-head { background: var(--rail-bg); }
            html .doc-intro { background: var(--sk-head); border-left: 3px solid var(--sk-accent); }
            html h2 span { color: var(--sk-secondary); }
        }
    </style>
</head>
<body>
@php
    $sections = [
        'about' => 'About SIGLA and these Terms',
        'users' => 'Who may use SIGLA',
        'account' => 'Your account',
        'use' => 'Acceptable use',
        'privacy' => 'Learner information and data privacy',
        'audit' => 'Your activity is recorded',
        'judgment' => 'SIGLA supports, but does not replace, professional judgment',
        'services' => 'Outside services',
        'retention' => 'Keeping and deleting records',
        'availability' => 'Availability',
        'suspension' => 'Suspension and removal of access',
        'changes' => 'Changes to these Terms',
        'contact' => 'Questions and concerns',
    ];
    $n = array_flip(array_keys($sections));
    $no = fn (string $key): string => ($n[$key] + 1).'.';
@endphp
<main class="doc">
    <header class="doc-head">
        <img src="{{ asset('images/sigla-logo.png') }}" alt="" aria-hidden="true">
        <div>
            <h1>Terms of Service</h1>
            <p>SIGLA &middot; School-Based Information Governance for Life Care Administration &middot; Version {{ \App\Support\TermsOfService::VERSION }}, effective {{ \App\Support\TermsOfService::effectiveLabel() }}</p>
        </div>
    </header>

    <div class="doc-body">
        <p class="doc-intro">
            These Terms govern your use of SIGLA, the school health information system of your school. SIGLA holds the
            health records of minors. By requesting an account, signing in or using SIGLA, you agree to these Terms. If
            you do not agree, do not use SIGLA.
        </p>

        <ol class="toc" aria-label="Contents">
            @foreach ($sections as $key => $title)
                <li><a href="#{{ $key }}">{{ $title }}</a></li>
            @endforeach
        </ol>

        <section id="about">
            <h2><span>{{ $no('about') }}</span>{{ $sections['about'] }}</h2>
            <p>
                SIGLA is a web-based system used by a public school to manage the health and nutrition of its learners.
                It keeps each learner&rsquo;s school health card and medical examination, clinic consultations and vital
                signs, deworming, medicine stock and dispensing, incident reports, parental consent forms
                (Sulat-Pahibalo), nutritional status by BMI, the School-Based Feeding Program (SBFP) &mdash; enrollment,
                daily attendance and follow-up &mdash; and the DepEd forms and reports built from these records.
            </p>
            <p>
                SIGLA is operated by the school for official school purposes only. These Terms apply to every person who
                uses it, in every role.
            </p>
        </section>

        <section id="users">
            <h2><span>{{ $no('users') }}</span>{{ $sections['users'] }}</h2>
            <p>
                Only authorized personnel of the school may use SIGLA, each in one role: School Nurse, Clinic Staff,
                Clinic Teacher, Class Adviser, School Head, Feeding Program Coordinator, Nutrition Coordinator, or
                System Administrator. Learners and parents do not have accounts.
            </p>
            <ul>
                <li>An account is given only after a request is reviewed and approved by the System Administrator. Approval may be refused.</li>
                <li>You must give your true name and the role and, for a Class Adviser, the grade and section you actually hold.</li>
                <li>Each role sees and changes only what that role needs. A Class Adviser sees only their own class; the School Head reads and monitors but does not change records; only the School Nurse and Clinic Staff manage medicine stock; Clinic Staff and Clinic Teachers may dispense only paracetamol.</li>
            </ul>
        </section>

        <section id="account">
            <h2><span>{{ $no('account') }}</span>{{ $sections['account'] }}</h2>
            <ul>
                <li>Your account is for you alone. Never share your username or password, and never sign in as someone else.</li>
                <li>Use a strong password and change it if you think someone else knows it.</li>
                <li>Use &ldquo;Keep me signed in&rdquo; only on a device that is yours. Sign out when you leave a shared or school computer.</li>
                <li>You are responsible for everything done under your account. Tell the System Administrator at once if you suspect it has been used by someone else.</li>
                <li>Repeated failed sign-in attempts are temporarily blocked to protect the records.</li>
            </ul>
        </section>

        <section id="use">
            <h2><span>{{ $no('use') }}</span>{{ $sections['use'] }}</h2>
            <p>You agree to use SIGLA only to do your job for the school. In particular, you will:</p>
            <ul>
                <li>open a learner&rsquo;s record only when your work requires it &mdash; never out of curiosity, and never for a learner who is not your responsibility;</li>
                <li>enter information that is accurate and complete, as observed or measured, and correct a mistake through the system rather than leave it;</li>
                <li>not copy, photograph, screenshot, download, print or share learners&rsquo; information except for an official school purpose, and never post it on social media or send it through personal accounts;</li>
                <li>keep printed and downloaded forms (health cards, MLAT sheets, consent forms, master lists, SF2 attendance, BMI reports) as confidential documents, and dispose of them as your school directs;</li>
                <li>upload only genuine school documents and photographs that belong in the learner&rsquo;s record;</li>
                <li>not try to get around SIGLA&rsquo;s access controls, reach records outside your role or school, test its security, or disrupt it.</li>
            </ul>
        </section>

        <section id="privacy">
            <h2><span>{{ $no('privacy') }}</span>{{ $sections['privacy'] }}</h2>
            <p>
                Learners&rsquo; health records are <strong>sensitive personal information</strong> under the Data Privacy
                Act of 2012 (Republic Act No. 10173), and most learners are minors. The school processes this information
                to deliver school health and nutrition services, to run the feeding program, and to prepare the reports
                DepEd requires. Parental consent for school health services is collected through the consent forms
                recorded in SIGLA.
            </p>
            <ul>
                <li>You must keep learners&rsquo; information confidential during and after your use of SIGLA, and use it only for the purpose it was collected for.</li>
                <li>SIGLA encrypts names, contact details, health information, consent answers and signatures when it stores them, and keeps each school&rsquo;s records separate from every other school&rsquo;s.</li>
                <li>If you learn that learners&rsquo; information has been lost, disclosed or accessed without authority, report it immediately to the System Administrator and your school&rsquo;s Data Protection Officer.</li>
                <li>Requests from a learner or parent to see or correct their information are handled by the school, following its data privacy procedures.</li>
            </ul>
        </section>

        <section id="audit">
            <h2><span>{{ $no('audit') }}</span>{{ $sections['audit'] }}</h2>
            <p>
                SIGLA keeps an audit trail of sign-ins, of who opened sensitive records, and of every record created,
                changed or removed, with the person and the time. The audit trail cannot be edited or deleted. It may be
                reviewed by the school to protect learners&rsquo; records and to look into suspected misuse. Your agreement
                to these Terms is itself recorded there, with the version you agreed to.
            </p>
        </section>

        <section id="judgment">
            <h2><span>{{ $no('judgment') }}</span>{{ $sections['judgment'] }}</h2>
            <p>
                SIGLA computes and suggests: BMI and nutritional status on the WHO growth reference, attendance rates and
                follow-up flags, medicine stock warnings, possible allergy matches, and readings of photographed forms.
                These are aids. They are not a diagnosis or treatment, and they can be wrong.
            </p>
            <ul>
                <li>The School Nurse and clinic personnel remain responsible for every health decision, and a learner needing urgent care is handled under the school&rsquo;s emergency procedures, not through SIGLA.</li>
                <li>A learner is placed on the feeding program&rsquo;s follow-up list only after the Feeding Program Coordinator confirms the record. Nothing in SIGLA removes a learner from the program on its own.</li>
                <li>Check every suggestion before you rely on it or save it.</li>
            </ul>
        </section>

        <section id="services">
            <h2><span>{{ $no('services') }}</span>{{ $sections['services'] }}</h2>
            <p>Some features send limited information to outside services so they can work:</p>
            <ul>
                <li><strong>Reading photographed forms.</strong> When you upload a photo of a signed consent form, a class masterlist or a feeding attendance sheet, or ask for a clinical note to be proofread, the image or text is sent to an artificial-intelligence service (Google Gemini or Anthropic Claude) to read it. A person must review what it reads before anything is saved. Use these features only with documents that belong in SIGLA.</li>
                <li><strong>Allergy check.</strong> Medicine and allergy names, never learners&rsquo; names, are checked against RxNorm, a public service of the U.S. National Library of Medicine.</li>
                <li><strong>School calendar.</strong> National holidays are read from Calendarific. No learner information is sent.</li>
            </ul>
        </section>

        <section id="retention">
            <h2><span>{{ $no('retention') }}</span>{{ $sections['retention'] }}</h2>
            <p>
                Records are kept by school year: a learner&rsquo;s record for one year is never overwritten by the next.
                When a learner leaves the school, their records are archived and later deleted on a schedule set by the
                school. Records may be changed or removed only through the functions SIGLA provides for your role.
                Audit records are kept as long as the school requires.
            </p>
        </section>

        <section id="availability">
            <h2><span>{{ $no('availability') }}</span>{{ $sections['availability'] }}</h2>
            <p>
                SIGLA is provided for the school&rsquo;s use as it is. It may be unavailable at times for maintenance or
                because of network problems. Keep any paper records your school or DepEd requires, and do not rely on
                SIGLA as the only copy of a document you are required to keep.
            </p>
        </section>

        <section id="suspension">
            <h2><span>{{ $no('suspension') }}</span>{{ $sections['suspension'] }}</h2>
            <p>
                The System Administrator may suspend or remove an account when a person leaves the school or changes
                assignment, or when these Terms are broken. Misuse of learners&rsquo; information may also lead to
                administrative action under DepEd rules and to liability under the Data Privacy Act of 2012.
            </p>
        </section>

        <section id="changes">
            <h2><span>{{ $no('changes') }}</span>{{ $sections['changes'] }}</h2>
            <p>
                These Terms may be updated when SIGLA or the rules it follows change. The version and effective date are
                shown at the top of this page. Continuing to use SIGLA after a change means you accept the updated Terms.
            </p>
        </section>

        <section id="contact">
            <h2><span>{{ $no('contact') }}</span>{{ $sections['contact'] }}</h2>
            <p>
                For questions about your account or these Terms, contact your school&rsquo;s System Administrator. For
                questions about how learners&rsquo; personal information is handled, contact your school&rsquo;s Data
                Protection Officer.
            </p>
        </section>

        <footer class="doc-foot">
            <span>Version {{ \App\Support\TermsOfService::VERSION }} &middot; Effective {{ \App\Support\TermsOfService::effectiveLabel() }}</span>
            <span>
                <a href="{{ route('account.request') }}">Create Account</a>
                <a href="{{ route('login') }}">Back to Login</a>
            </span>
        </footer>
    </div>
</main>
</body>
</html>
