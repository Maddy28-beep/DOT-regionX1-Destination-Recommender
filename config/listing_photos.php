<?php

return [
    /*
     * Where "Report a photo" goes. The link is shown only when an address is set, so it never points nowhere.
     * Set LISTING_PHOTO_REPORT_EMAIL on the server to the address that will answer removal requests.
     */
    'report_email' => env('LISTING_PHOTO_REPORT_EMAIL'),

    // How far a picture has been checked. Only `approved` pictures are imported from the manifest.
    'statuses' => ['unverified', 'identity_checked', 'second_source', 'approved', 'rejected'],

    // Where a picture came from.
    'source_types' => [
        'own_photo', 'establishment_supplied', 'official_website', 'official_facebook', 'google_business_profile',
        'dot_or_lgu', 'wikimedia_commons', 'tourism_directory', 'other',
    ],

    /*
     * Why it may be shown. `establishment_meeting` is the owners' consent given in person at the meeting before the
     * system was built: it needs a reference to that meeting (permission_ref) and its date.
     */
    'permission_bases' => ['establishment_owner', 'establishment_meeting', 'own_work', 'dot_written_permission', 'open_licence'],

    'listing_kinds' => ['Destination', 'Accommodation', 'Restaurant', 'SouvenirCenter', 'Package', 'TourOperator'],
];
