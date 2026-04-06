<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdviserController extends Controller
{
    public function create(): View
    {
        return view('adviser.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $records = $request->session()->get('school_health_card_records', []);

        $records[] = [
            'last_name' => $request->input('last_name'),
            'first_name' => $request->input('first_name'),
            'middle_name' => $request->input('middle_name'),
            'lrn' => $request->input('lrn'),
            'birth_month' => $request->input('birth_month'),
            'birth_day' => $request->input('birth_day'),
            'birth_year' => $request->input('birth_year'),
            'birthplace' => $request->input('birthplace'),
            'parent_guardian' => $request->input('parent_guardian'),
            'address' => $request->input('address'),
            'school_id' => $request->input('school_id'),
            'region' => $request->input('region'),
            'division' => $request->input('division'),
            'telephone_no' => $request->input('telephone_no'),
            'height_cm' => $request->input('height_cm'),
            'weight_kg' => $request->input('weight_kg'),
            'grade_level' => $request->input('grade_level'),
            'examination' => [],
        ];

        $request->session()->put('school_health_card_records', $records);

        return redirect()
            ->route('dashboard.class-adviser')
            ->with('success', 'Record submitted to School Nurse.');
    }

    public function success(): View
    {
        return view('adviser.success');
    }
}
