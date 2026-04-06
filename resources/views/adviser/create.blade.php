<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Adviser Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">School Health Card Prototype - Class Adviser</h1>
        <a href="{{ route('nurse.index') }}" class="btn btn-outline-primary btn-sm">Go to Nurse Dashboard</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('adviser.store') }}">
                @csrf

                <h2 class="h6 text-secondary mt-1">Student Info</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">LRN</label>
                        <input type="text" name="lrn" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birth Month</label>
                        <input type="text" name="birth_month" class="form-control" placeholder="MM">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birth Day</label>
                        <input type="text" name="birth_day" class="form-control" placeholder="DD">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birth Year</label>
                        <input type="text" name="birth_year" class="form-control" placeholder="YYYY">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Birthplace</label>
                        <input type="text" name="birthplace" class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Parent/Guardian</label>
                        <input type="text" name="parent_guardian" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">School ID</label>
                        <input type="text" name="school_id" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Region</label>
                        <input type="text" name="region" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Division</label>
                        <input type="text" name="division" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Telephone No.</label>
                        <input type="text" name="telephone_no" class="form-control">
                    </div>
                </div>

                <hr class="my-4">

                <h2 class="h6 text-secondary">Health Measurements</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Height (cm)</label>
                        <input type="text" name="height_cm" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Weight (kg)</label>
                        <input type="text" name="weight_kg" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Grade Level</label>
                        <select name="grade_level" class="form-select">
                            <option value="">Select Grade Level</option>
                            <option>Kinder/SPED</option>
                            <option>Grade 1/SPED</option>
                            <option>Grade 2/SPED</option>
                            <option>Grade 3/SPED</option>
                            <option>Grade 4/SPED</option>
                            <option>Grade 5/SPED</option>
                            <option>Grade 6/SPED</option>
                            <option>Grade 7/SPED</option>
                            <option>Grade 8/SPED</option>
                            <option>Grade 9/SPED</option>
                            <option>Grade 10/SPED</option>
                            <option>Grade 11/SPED</option>
                            <option>Grade 12/SPED</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success">Submit to School Nurse</button>
                    <a href="{{ route('nurse.index') }}" class="btn btn-outline-secondary">View Nurse Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
