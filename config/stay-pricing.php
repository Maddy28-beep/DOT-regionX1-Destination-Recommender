<?php

return [
    // Public room rates, not live booking quotes. Match the full property name to avoid misattribution.
    'published' => [
        'Paradise Island Park and Beach Resort' => [
            'min' => 7000,
            'max' => 8200,
            'source' => 'https://paradiseislandsamal.com/rooms/',
            'checked' => '2026-11-17',
            'note' => 'Published Deluxe through Classic Premier room rates for two guests. Group per-person rates are excluded. Confirm taxes, inclusions and availability with the property.',
        ],
    ],
    // Broad editorial planning allowances, NOT researched quotes for individual properties.
    'estimates' => [
        'Hotel' => [1500, 7000],
        'Apartment Hotel' => [1500, 6000],
        'Condominium Unit' => [1500, 5000],
        'Homestay' => [800, 3500],
        'Beach Resort' => [2000, 12000],
        'Resort' => [2000, 12000],
        'default' => [1500, 8000],
    ],
    'tier_estimates' => [
        'Budget-Friendly' => [500, 2500],
        'Mid-range' => [2500, 7000],
        'Premium' => [7000, 25000],
    ],
];
