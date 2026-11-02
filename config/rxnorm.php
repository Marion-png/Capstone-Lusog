<?php

/*
 * Names used in the Philippines that RxNorm, a U.S. vocabulary, files under
 * another name. A typed medicine or allergy is replaced by its RxNorm name
 * before the lookup (App\Support\RxNormService::normalize). Keys are matched
 * on the whole name, lower case.
 *
 * Add the clinic's own stock here as it is found: a brand name RxNorm does
 * not carry, or a generic it spells differently.
 */
return [
    'synonyms' => [
        'paracetamol' => 'acetaminophen',
        'biogesic' => 'acetaminophen',
        'salbutamol' => 'albuterol',
        'cotrimoxazole' => 'sulfamethoxazole / trimethoprim',
        'co-trimoxazole' => 'sulfamethoxazole / trimethoprim',
        'co-amoxiclav' => 'amoxicillin / clavulanate',
        'coamoxiclav' => 'amoxicillin / clavulanate',
        // RxNorm's ingredient is "mefenamate"; its approximate search reads
        // "mefenamic acid" as meclofenamic acid.
        'mefenamic acid' => 'mefenamate',
    ],
];
