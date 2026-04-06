<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Submitted</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm mx-auto" style="max-width: 640px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Record submitted to School Nurse.</h1>
            <p class="text-muted mb-4">The student info and adviser measurements are now stored in session for prototype workflow testing.</p>
            <div class="d-flex gap-2">
                <a href="{{ route('adviser.create') }}" class="btn btn-primary">Add Another Student</a>
                <a href="{{ route('nurse.index') }}" class="btn btn-outline-success">Open Nurse Dashboard</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
