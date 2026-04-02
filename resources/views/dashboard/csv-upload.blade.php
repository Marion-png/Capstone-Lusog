<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CSV Upload - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: #f7f8f5; color: #0d1f14; }
        .wrap { max-width: 1080px; margin: 30px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e4ece7; border-radius: 14px; box-shadow: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06); overflow: hidden; }
        .head { padding: 16px 18px; border-bottom: 1px solid #e4ece7; }
        .title { font-family: 'DM Serif Display', serif; font-size: 1.6rem; }
        .sub { color: #7a9e87; font-size: .84rem; margin-top: 4px; }
        .body { padding: 18px; }
        .flash { margin-bottom: 12px; padding: 10px 12px; border-radius: 10px; border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; font-size: .82rem; font-weight: 600; }
        .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .file { border: 1px solid #e4ece7; border-radius: 10px; padding: 8px; background: #fff; }
        .btn { border: none; border-radius: 10px; padding: 10px 14px; font-size: .84rem; font-weight: 600; cursor: pointer; }
        .btn-parse { background: #166534; color: #fff; }
        .btn-submit { background: #15803d; color: #fff; }
        .helper { font-size: .75rem; color: #7a9e87; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e4ece7; padding: 9px 10px; text-align: left; font-size: .8rem; }
        th { background: #f7f8f5; font-size: .68rem; text-transform: uppercase; letter-spacing: .08em; color: #7a9e87; }
        .empty { color: #7a9e87; font-size: .82rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div class="title">CSV Upload and Auto-Encoding</div>
            <div class="sub">Upload BMI and nutritional data from CSV, preview rows, then encode into the database.</div>
        </div>
        <div class="body">
            @if (session('success'))
                <div class="flash">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ url('/csv/upload') }}" id="csv-form">
                @csrf
                <div class="row">
                    <input type="file" id="csv-file" class="file" accept=".csv" required>
                    <button type="button" id="parse-btn" class="btn btn-parse">Preview CSV</button>
                    <button type="submit" class="btn btn-submit">Submit</button>
                </div>
                <div class="helper">Expected columns: Student Name, Student ID, Section, Weight (kg), BMI Value, Nutritional Status</div>
                <input type="hidden" name="rows_json" id="rows-json">
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Section</th>
                            <th>Weight (kg)</th>
                            <th>BMI Value</th>
                            <th>Nutritional Status</th>
                        </tr>
                    </thead>
                    <tbody id="preview-body">
                        <tr>
                            <td colspan="6" class="empty">No CSV parsed yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js"></script>
<script>
    const fileInput = document.getElementById('csv-file');
    const parseBtn = document.getElementById('parse-btn');
    const previewBody = document.getElementById('preview-body');
    const rowsJson = document.getElementById('rows-json');
    const form = document.getElementById('csv-form');

    let parsedRows = [];

    parseBtn.addEventListener('click', function () {
        const file = fileInput.files[0];

        if (!file) {
            alert('Please choose a CSV file first.');
            return;
        }

        Papa.parse(file, {
            header: true,
            skipEmptyLines: true,
            complete: function (results) {
                parsedRows = results.data || [];
                rowsJson.value = JSON.stringify(parsedRows);

                if (!parsedRows.length) {
                    previewBody.innerHTML = '<tr><td colspan="6" class="empty">No rows found in file.</td></tr>';
                    return;
                }

                previewBody.innerHTML = parsedRows.map(function (row) {
                    return '<tr>' +
                        '<td>' + (row['Student Name'] || '') + '</td>' +
                        '<td>' + (row['Student ID'] || '') + '</td>' +
                        '<td>' + (row['Section'] || '') + '</td>' +
                        '<td>' + (row['Weight (kg)'] || '') + '</td>' +
                        '<td>' + (row['BMI Value'] || '') + '</td>' +
                        '<td>' + (row['Nutritional Status'] || '') + '</td>' +
                        '</tr>';
                }).join('');
            }
        });
    });

    form.addEventListener('submit', function (event) {
        if (!parsedRows.length) {
            event.preventDefault();
            alert('Please click Preview CSV before submitting.');
        }
    });
</script>
</body>
</html>
