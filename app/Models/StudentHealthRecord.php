<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentHealthRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_name',
        'student_id',
        'section',
        'weight',
        'bmi_value',
        'nutritional_status',
        'baseline_age',
        'baseline_height_cm',
        'baseline_weight_kg',
        'baseline_bmi_value',
        'baseline_nutritional_status',
        'baseline_recorded_at',
        'endline_age',
        'endline_height_cm',
        'endline_weight_kg',
        'endline_bmi_value',
        'endline_nutritional_status',
        'endline_recorded_at',
        'attendance_sessions_count',
        'is_at_risk',
    ];

    protected $casts = [
        'baseline_recorded_at' => 'date',
        'endline_recorded_at' => 'date',
        'is_at_risk' => 'boolean',
    ];
}
