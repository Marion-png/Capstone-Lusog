<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sulat-Pahibalo - {{ $form->student_name }}</title>
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    <style>
        body { background: #fff; }
        .cf-wrap { max-width: 800px; }
        @media screen {
            .print-bar { position: sticky; top: 0; background: var(--g900); color: #fff; padding: 10px 20px; display: flex; gap: 12px; align-items: center; font-family: 'DM Sans', sans-serif; font-size: .84rem; }
        }
    </style>
</head>
<body onload="window.print()">
<div class="print-bar no-print">
    Use your browser's print dialog to print or save as PDF.
    <button type="button" class="cf-btn cf-btn-outline" onclick="window.print()">Print Again</button>
    <button type="button" class="cf-btn cf-btn-ghost" onclick="window.close()">Close</button>
</div>
<div class="cf-wrap">
    @include('consent-forms._document', ['form' => $form, 'mode' => 'locked'])
</div>
</body>
</html>
