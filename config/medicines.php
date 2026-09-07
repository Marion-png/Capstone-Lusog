<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DepEd-allowed medicines
    |--------------------------------------------------------------------------
    |
    | The school clinic's permitted stock list, grouped as a nurse would look
    | for it. The Add Medicine form offers these instead of a free-text box,
    | so one school's "Paracetamol 500mg" and another's "paracetamol 500 mg"
    | do not become two items nobody can total.
    |
    | PROVISIONAL — replace with the official DepEd list.
    |
    | This list was assembled from the medicines the system already handles and
    | from what a school clinic typically stocks. The school nurse has offered
    | the official DepEd list; when it arrives, replace the entries below with
    | it. Nothing else needs to change — the form, the validator and the
    | reports all read this file through App\Support\MedicineCatalogue.
    |
    | Editing rules:
    |   - The value on the left is what gets stored. Changing it renames the
    |     medicine everywhere; adding a new one is safe at any time.
    |   - Keep names in the form the nurse writes on a requisition, including
    |     the strength, so the stock list matches the paperwork.
    |
    */

    'catalogue' => [

        'Analgesic / Antipyretic' => [
            'Paracetamol 500mg tablet',
            'Paracetamol 250mg/5ml suspension',
            'Ibuprofen 200mg tablet',
        ],

        'Antiseptic / Wound care' => [
            'Povidone-iodine 10% solution',
            'Hydrogen peroxide 3% solution',
            'Sterile gauze pad',
            'Adhesive bandage',
            'Elastic bandage',
            'Cotton balls',
            'Micropore tape',
        ],

        'Gastrointestinal' => [
            'Oral Rehydration Salts (ORS) sachet',
            'Antacid tablet',
            'Hyoscine butylbromide 10mg tablet',
        ],

        'Vitamins / Supplements' => [
            'Ferrous sulfate tablet',
            'Ferrous sulfate syrup',
            'Vitamin A capsule 200,000 IU',
            'Ascorbic acid 500mg tablet',
            'Multivitamins syrup',
        ],

        'Deworming' => [
            'Albendazole 400mg tablet',
            'Mebendazole 500mg tablet',
        ],

        'Topical' => [
            'Calamine lotion',
            'Hydrocortisone 1% cream',
            'Antifungal cream',
        ],

        'Other supplies' => [
            'Oral thermometer probe cover',
            'Disposable gloves',
            'Face mask',
            'Ice pack',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Medicines outside the list
    |--------------------------------------------------------------------------
    |
    | The school nurse stated plainly that she dispenses beyond the official
    | list when it is clinically necessary — antibiotics, mefenamic acid,
    | allergy medicine. That is real practice, and a system that refuses to
    | record it does not stop it happening; it only stops the record being
    | true. An off-list medicine kept in a notebook is worse than one on the
    | screen, because nobody can count it, reorder it, or answer for it.
    |
    | So the form allows one, marks it as off-list, and requires a reason. Set
    | this to false only if a school decides the catalogue is a hard limit —
    | and understand that the stock list will then be incomplete rather than
    | the practice being different.
    |
    */

    'allow_off_catalogue' => true,

];
