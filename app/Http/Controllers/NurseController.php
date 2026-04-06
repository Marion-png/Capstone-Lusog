<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NurseController extends Controller
{
    public function index(Request $request): View
    {
        $records = $request->session()->get('school_health_card_records', []);

        return view('nurse.index', [
            'records' => $records,
        ]);
    }

    public function examine(Request $request, int $index): View
    {
        $records = $request->session()->get('school_health_card_records', []);

        if (!isset($records[$index])) {
            abort(404);
        }

        return view('nurse.examine', [
            'index' => $index,
            'record' => $records[$index],
        ]);
    }

    public function saveExamination(Request $request, int $index): RedirectResponse
    {
        $records = $request->session()->get('school_health_card_records', []);

        if (!isset($records[$index])) {
            abort(404);
        }

        $records[$index]['height_cm'] = $request->input('height_cm', $records[$index]['height_cm'] ?? null);
        $records[$index]['weight_kg'] = $request->input('weight_kg', $records[$index]['weight_kg'] ?? null);
        $records[$index]['examination'] = [
            'date_of_examination' => $request->input('date_of_examination'),
            'temperature_bp' => $request->input('temperature_bp'),
            'heart_rate' => $request->input('heart_rate'),
            'pulse_rate' => $request->input('pulse_rate'),
            'respiratory_rate' => $request->input('respiratory_rate'),
            'height_cm' => $request->input('height_cm'),
            'weight_kg' => $request->input('weight_kg'),
            'nutritional_status_bmi' => $request->input('nutritional_status_bmi'),
            'nutritional_status_height_age' => $request->input('nutritional_status_height_age'),
            'vision_screening' => $request->input('vision_screening'),
            'auditory_screening' => $request->input('auditory_screening'),
            'skin_scalp' => $request->input('skin_scalp'),
            'eyes_ears_nose' => $request->input('eyes_ears_nose'),
            'mouth_throat_neck' => $request->input('mouth_throat_neck'),
            'lungs_heart' => $request->input('lungs_heart'),
            'abdomen' => $request->input('abdomen'),
            'deformities' => $request->input('deformities'),
            'iron_supplementation' => $request->input('iron_supplementation'),
            'deworming' => $request->input('deworming'),
            'immunization' => $request->input('immunization'),
            'sbfp_beneficiary' => $request->input('sbfp_beneficiary'),
            'four_ps_beneficiary' => $request->input('four_ps_beneficiary'),
            'menarche' => $request->input('menarche'),
            'others' => $request->input('others'),
            'examined_by' => $request->input('examined_by'),
        ];

        $request->session()->put('school_health_card_records', $records);

        return redirect()->route('nurse.index')->with('success', 'Medical record saved.');
    }
}
