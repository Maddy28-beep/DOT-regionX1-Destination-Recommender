# Distinct dining illustrations

Published 17 November 2026 for the public dining catalog and restaurant detail covers.

The public inventory contained 64 restaurants. Seven already had genuine cover photographs and retain those exact photos and records. The other 57 now each have a distinct AI-generated cuisine illustration. Six images came from the initial dining image pack and 51 additional images complete the set. Each is a separate generated composition, rather than a renamed or recolored copy. The images are generic illustrative food, not evidence of an establishment's actual menu or premises.

## Assets and provenance

`public/images/dining-illustrations-v1/manifest.json` records each intended restaurant's ID, name, slug, filename and SHA-256. The 57 optimized WebP files are 960 × 640 and total 7,560,196 bytes. `docs/dining-illustrations-2026-11-17-prompts.json` records the exact final prompts, generation method, original output identifier and disclosure for every image. Generation used the built-in image tool. High-resolution PNG originals remain in the local design workspace.

## Import behavior

`DiningIllustrationSeeder` validates all filenames, dimensions and content hashes before writing. It checks ID/name/slug together, skips listings with genuine covers, refuses to overwrite differing stored files and adds photo records idempotently. Existing photos and restaurant fields stay unchanged. New rows use `Sample image`, `is_primary = false`, explicit AI provenance and `verification_status = unverified`. Their sort order puts them ahead of the old generic illustration. Existing card and gallery disclosure remains in effect. Future genuine uploads automatically take precedence.

The source assets live in public images; the importer copies identical bytes to `listings/illustrations/dining-20261117/` on the public storage disk. No recommender inputs, survey transactions, accreditation fields or listing facts change.

## Validation and publication

`deploy/check-dining-illustrations.php` passed 23 checks in the isolated app. Fixture writes used an outer rollback transaction and private temporary storage. Coverage includes original photo and restaurant preservation, empty and amenity-only listings, repeated imports, card/gallery disclosure, later owner photos, invalid paths, mismatched content hashes and listing identity changes. PHP syntax and whitespace checks passed. Full PHPUnit was not run.

The deployment archive SHA-256 was `8b391a0f9242bf21085fc0b10724584dd6231514c5c47b84fdc6dd57f7299661`. Installation refused differing existing files and compared every installed file with staging. `deploy/publish-dining-illustrations.php` performed a dry run, saved an on-server backup, and imported atomically. Its postconditions confirmed 64 distinct displayed image hashes, 57 illustrations, 7 preserved genuine covers and unchanged preexisting photo metadata and restaurant business fields.

Backup: `/var/backups/exploredvo/dining-illustrations-20261117T134734Z`. `before.json` contains the previous cover/photo records and restaurant field hashes. `published.json` records the exact newly inserted photo IDs. A rollback should remove only those IDs after checking their source marker and generated path prefix, leaving all preexisting rows and uploaded files in place. Do not delete whole public-storage directories or remove genuine photos.

No web template or runtime service configuration changed. The importer is explicit and is not automatically added to the application's default seed sequence.

Live browser checks traversed all six catalog pages: 64 listing cards, 64 distinct image URLs, every card image loaded, and all 57 generated images retained their Illustration labels. Two public restaurant details showed their new cover and visible illustration disclosure. Desktop (1440 px) and mobile (390 px) screenshots were inspected. The first disclosure lookup hit a hidden mobile duplicate on desktop; checking visible disclosure elements resolved the test without any application change.
