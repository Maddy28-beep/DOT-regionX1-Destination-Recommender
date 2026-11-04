# Drive photo batch (2026-11-05)

48 pictures from the team's shared folder `Establishment_DOT`, matched to listings by name. They are for BETA TESTING.
Permission for each picture has not been confirmed with its owner; the project owner decided to publish them for testing
(`permission_basis: team_testing_decision`) and to re-review the whole batch after DOT approves the system.

Left out on purpose: pictures that were too small or poor (29), low-confidence matches (5), exact duplicates (7),
pictures carrying another photographer's or website's watermark/logo (7), and pictures whose place is not in the catalogue (13).

Import (idempotent, skips any name it cannot find exactly once):

    php artisan db:seed --class=DriveBatchPhotoSeeder

Re-review later: `listing_photos` rows with `permission_basis = 'team_testing_decision'`.
