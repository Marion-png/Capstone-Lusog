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
    ];
}
