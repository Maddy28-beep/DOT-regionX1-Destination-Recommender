# DOT Davao MICE Guidebook photo batch (2026-11-17)

44 pictures for 43 stays, taken from the "Hotels, Resorts, and Venues" section (PDF pages 68 to 81) of the DOT Region XI
Davao MICE Guidebook 2024. Each hotel has one picture there, printed above its heading; each was matched to the stay by
that heading, which was read from the page. Many pictures show a function hall, because it is a venue guide.

Permission: the project owner confirmed on 2026-11-17 that permission exists to use these pictures. The entries are marked
`team_testing_decision` until the permission letter is attached; then change `permission_basis` to `dot_written_permission`
and put the letter's reference in `permission_ref`. The credit shown under each picture names the hotel and DOT Region XI.

Left out because the match was not certain: "Panorama Hotel" (the system has "Panorama Summit Hotel"), "Sans Hotel at
Luxebridge Davao" (the system has "Luxebridge Hotel"), and SMX Convention Center (not a stay). The guidebook has no
restaurant listing, so nothing here is for Dining.

Pictures were resized to 1600 px wide and are otherwise unchanged.

Import (idempotent; skips any name it cannot find exactly once):

    php artisan db:seed --class=GuidebookBatchPhotoSeeder

Check the manifest any time:

    php artisan listing-photos:validate --path=database/data/listing-photos/guidebook-2026-11
