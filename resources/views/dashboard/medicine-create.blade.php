<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Add Medicine - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/school-nurse-medicine-create.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div>
                <div class="title">Add Medicine</div>
                <div class="sub">Create a new medicine record for the school clinic inventory.</div>
            </div>
            <a href="{{ route('dashboard.medicine-inventory') }}" class="btn btn-ghost">Back to Inventory</a>
        </div>
        <div class="body">
            <form method="POST" action="{{ route('medicine-inventory.store') }}">
                @csrf
                @include('partials.medicine-form-fields', ['gridClass' => 'grid'])

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Save Medicine</button>
                    <a href="{{ route('dashboard.medicine-inventory') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
