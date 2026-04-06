<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Nurse Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">School Nurse Dashboard</h1>
        <a href="{{ route('adviser.create') }}" class="btn btn-outline-primary btn-sm">Class Adviser Form</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Student Name</th>
                        <th>LRN</th>
                        <th>Grade Level</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th style="width: 220px;">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($records as $index => $record)
                        @php
                            $middle = trim((string) ($record['middle_name'] ?? ''));
                            $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                            $fullName = trim(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . $middleInitial);
                            $examined = !empty($record['examination']);
                        @endphp
                        <tr>
                            <td>{{ $fullName }}</td>
                            <td>{{ $record['lrn'] ?? '' }}</td>
                            <td>{{ $record['grade_level'] ?? '' }}</td>
                            <td>{{ $record['height_cm'] ?? '' }}</td>
                            <td>{{ $record['weight_kg'] ?? '' }}</td>
                            <td>
                                <a href="{{ route('nurse.examine', $index) }}" class="btn btn-sm btn-primary">Fill Medical Record</a>
                                @if ($examined)
                                    <span class="badge text-bg-success">Completed</span>
                                @else
                                    <span class="badge text-bg-secondary">Pending</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No submitted adviser records yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
