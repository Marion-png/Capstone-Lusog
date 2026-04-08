<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Add Medicine - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/school-nurse-medicine-create.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
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
                <div class="grid">
                    <div class="field full">
                        <label for="name">Medicine Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="e.g. Paracetamol" required>
                        @error('name') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="stock_quantity">Current Stock</label>
                        <input id="stock_quantity" name="stock_quantity" type="number" min="0" value="{{ old('stock_quantity', 0) }}" required>
                        @error('stock_quantity') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="minimum_threshold">Minimum Threshold</label>
                        <input id="minimum_threshold" name="minimum_threshold" type="number" min="0" value="{{ old('minimum_threshold', 20) }}" required>
                        @error('minimum_threshold') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="unit">Unit</label>
                        <input id="unit" name="unit" type="text" value="{{ old('unit', 'pcs') }}" required>
                        @error('unit') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="notes">Notes</label>
                        <input id="notes" name="notes" type="text" value="{{ old('notes') }}" placeholder="Optional">
                        @error('notes') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>
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
