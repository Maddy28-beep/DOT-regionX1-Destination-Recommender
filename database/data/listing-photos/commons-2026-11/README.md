# Wikimedia Commons photo batch (2026-11-09)

14 pictures for listings that had no real photo (13 destinations in Davao City and Davao del Sur, 1 stay), each from
Wikimedia Commons under an open licence (CC BY or CC BY-SA). Each entry in `manifest.json` records the Commons file page
(the licence evidence), the photographer, the licence, and what the picture shows.

How they were chosen: the file had to be on Commons with a stated open licence; the file page and the picture itself had
to show that place (a first automatic match was rejected when the picture turned out to be elsewhere, for example a
"Japanese Tunnel" photo that is the Ilagan tunnel in Isabela, not Davao); close-ups of identifiable people were left out.
Pictures were resized to 1600 px wide from the originals and are otherwise unchanged. The credit and licence in each
entry are shown under the picture and must stay shown (CC BY and CC BY-SA require attribution).

Import (idempotent; skips any name it cannot find exactly once; tries a name as written, then with and without " [SAMPLE]"):

    php artisan db:seed --class=CommonsBatchPhotoSeeder

Check the manifest any time:

    php artisan listing-photos:validate --path=database/data/listing-photos/commons-2026-11

Re-review later: `listing_photos` rows with `permission_basis = 'open_licence'`. Remove a picture if a photographer or the
owner asks.

Still without a real photo: the other places in Davao City and Davao del Sur. Wikimedia Commons has no suitable open
photo for them (hotels and inns are almost all copyrighted by their owners). They need a photo from the owner, DOT or the
LGU with permission; see `needs-photo-commons-2026-11.csv`.
