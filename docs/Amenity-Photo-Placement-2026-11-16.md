# Amenity photo placement — 2026-11-16

An Isla-Agri restroom upload was correctly tagged Amenities / restroom but replaced its sample landscape in the public hero. Photo ordering preferred every real photograph over sample images, including feature evidence.

Cover selection now excludes photos with an amenity key or an Amenities/Accessibility category. Both eager and lazy model selection use the same eligibility rules. The shared public gallery filters these photos before suppressing sample fallbacks; the full photo relationship remains available to the amenity viewer and partner photo manager.

Partner uploads and cover deletion only assign cover status to eligible photos, including tour package uploads. The photo manager hides Make Cover for feature photos, and the controller rejects direct requests before changing existing cover flags. Upload guidance explains where feature photos appear.

No production database records or uploaded images were changed. Isla-Agri photo 458 remains Amenities / restroom. Its previous sample landscape is restored with the existing illustration disclosure; a real General or Exterior upload is needed for an authentic main photo.

## Validation

- `deploy/check-amenity-photo-placement.php`: 24 checks passed in the isolated current-code application. Covers uploads, eager/lazy cover selection, fallback rendering, amenity viewer retention, rejected cover requests, cover deletion, legacy feature tags, package uploads, and the actual Isla-Agri partner photo manager. Temporary metadata is rolled back and fixture uploads use private temporary storage.
- Live HTTPS page: hero payload contains the previous landscape fallback and excludes the restroom; the restroom remains in the page's amenity data.
- Read-only production check: original restroom file exists and its category/key are unchanged.
- PHP syntax checks and `git diff --check` passed. Full PHPUnit and visual browser QA were not run.

Seven application files were backed up with original SHA-256 guards before deployment. Backup: `/var/backups/exploredvo/amenity-photo-placement-20261116T065453Z`. Deployed files matched staged files; compiled views were cleared and PHP-FPM reloaded.
