<?php

namespace Database\Seeders;

use App\Models\Condition;
use Illuminate\Database\Seeder;

class ConditionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $conditions = [
            // Eye conditions
            ['name' => 'Inflamed eye/stye', 'category' => 'Eye'],
            ['name' => 'Eye irritation', 'category' => 'Eye'],
            ['name' => 'Conjunctivitis', 'category' => 'Eye'],

            // Ear and Nose conditions
            ['name' => 'Ear Problem', 'category' => 'ENT'],
            ['name' => 'Nose Bleeding', 'category' => 'ENT'],
            ['name' => 'Sinusistis/Acute Rhinitis', 'category' => 'ENT'],

            // Respiratory and Throat conditions
            ['name' => 'Sore throat', 'category' => 'Respiratory'],
            ['name' => 'Tonsilitis', 'category' => 'Respiratory'],
            ['name' => 'Cough', 'category' => 'Respiratory'],
            ['name' => 'Fever', 'category' => 'General'],
            ['name' => 'Cold', 'category' => 'Respiratory'],

            // Oral conditions
            ['name' => 'Inflamed Gum', 'category' => 'Oral'],
            ['name' => 'Toothache', 'category' => 'Oral'],

            // Gastrointestinal conditions
            ['name' => 'Headache', 'category' => 'Neurological'],
            ['name' => 'Hyperacidity', 'category' => 'Gastrointestinal'],
            ['name' => 'Dysmenorrhea', 'category' => 'Reproductive'],
            ['name' => 'Diarrhea/LBM', 'category' => 'Gastrointestinal'],
            ['name' => 'Abdominal Pain', 'category' => 'Gastrointestinal'],
            ['name' => 'Nausea/ Vomitting', 'category' => 'Gastrointestinal'],

            // Neurological conditions
            ['name' => 'Fainting', 'category' => 'Neurological'],
            ['name' => 'Dizziness', 'category' => 'Neurological'],

            // Wound and Injury conditions
            ['name' => 'Lacerated Wound', 'category' => 'Injury'],
            ['name' => 'Punctured Wound', 'category' => 'Injury'],
            ['name' => 'Incised Wound', 'category' => 'Injury'],
            ['name' => 'Abrasion', 'category' => 'Injury'],
            ['name' => 'Contusion', 'category' => 'Injury'],
            ['name' => 'Ulcer (Skin)', 'category' => 'Skin'],
            ['name' => 'Burn', 'category' => 'Injury'],

            // Skin conditions
            ['name' => 'Thea Flava', 'category' => 'Skin'],
            ['name' => 'Ringworm', 'category' => 'Skin'],
            ['name' => 'Boil', 'category' => 'Skin'],
            ['name' => 'Skin allergy', 'category' => 'Allergy'],

            // Other
            ['name' => 'Others', 'category' => 'Other'],
        ];

        foreach ($conditions as $condition) {
            Condition::firstOrCreate(
                ['name' => $condition['name']],
                ['category' => $condition['category']]
            );
        }
    }
}
