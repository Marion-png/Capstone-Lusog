<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CsvUploadController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        $rows = json_decode($request->input('rows_json', '[]'), true);

        if (! is_array($rows)) {
            $rows = [];
        }

        $encodedCount = 0;

        foreach ($rows as $row) {
            StudentHealthRecord::create([
                'student_name' => $row['Student Name'] ?? $row['student_name'] ?? '',
                'student_id' => $row['Student ID'] ?? $row['student_id'] ?? '',
                'school_name' => $row['School Name'] ?? $row['School'] ?? $row['school_name'] ?? $row['school'] ?? null,
                'section' => $row['Section'] ?? $row['section'] ?? '',
                'weight' => (float) ($row['Weight (kg)'] ?? $row['weight'] ?? 0),
                'bmi_value' => (float) ($row['BMI Value'] ?? $row['bmi_value'] ?? 0),
                'nutritional_status' => $row['Nutritional Status'] ?? $row['nutritional_status'] ?? '',
            ]);

            $encodedCount++;
        }

        return back()->with('success', $encodedCount.' records successfully encoded.');
    }
}
