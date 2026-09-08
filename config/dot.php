<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DOT Accreditation Portal
    |--------------------------------------------------------------------------
    |
    | Where a business goes to APPLY for DOT accreditation. This is the national
    | Department of Tourism system, not part of ExploreDVO.
    |
    | It is distinct from this site's own partner registration
    | (portal.establishment.register), which is where an already-accredited
    | establishment claims the catalog listing it has been matched to.
    |
    | The public "List your establishment" links now open that registration
    | form, not this URL. Sending every partner straight out to the national
    | system meant an already-accredited business -- the only kind this site
    | lists -- was bounced off-site for nothing before it could ask for an
    | account. The form links on to this portal from the accreditation-number
    | field, which is where a business that lacks one finds out it needs one.
    |
    | Kept in config so every view that links to it can't drift apart, and so
    | the URL can be changed without touching Blade if DOT moves it.
    |
    */

    'accreditation_portal' => env(
        'DOT_ACCREDITATION_URL',
        'https://accreditation.tourism.gov.ph/register'
    ),

    /*
    |--------------------------------------------------------------------------
    | DOT Region XI Contact Details
    |--------------------------------------------------------------------------
    |
    | Shown in the footer and on the legal pages (Privacy Policy, data
    | requests). Kept in one place so the footer and the privacy page can
    | never quote a different address or email from each other.
    |
    */

    'contact_email' => env('DOT_CONTACT_EMAIL', 'dot11@tourism.gov.ph'),

    // TODO: placeholder -- replace with DOT Region XI's actual current office
    // address before this ships. Not fabricated by design: an invented
    // government office address is exactly the kind of inaccurate official
    // claim this whole footer fix exists to remove.
    'office_address' => env('DOT_OFFICE_ADDRESS', 'DOT Region XI Office, Davao City, Davao Region'),

];
