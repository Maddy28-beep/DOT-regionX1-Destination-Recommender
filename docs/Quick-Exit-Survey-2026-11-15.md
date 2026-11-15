# Shorter visitor feedback

Published 2026-11-15 at https://www.exploredavao.com/exit-survey.

The initial exit survey now starts with the existing two required questions: overall rating and recommendation. Spending remains visible and optional, using six radio choices (the existing five ranges and prefer not to say). Its historical whole-trip scope still excludes accommodation paid separately in advance. No exact expense or completion time is claimed.

Three native disclosures contain the optional places/activities, trip context, and comments/planner ratings. No typing is necessary to submit. Existing backend validation, source separation, visit/activity storage, recap, and Apriori inputs are unchanged. Missing expenditure stays null. Optional sections reopen when they contain previous answers or validation errors.

The separate establishment review requires only its existing overall star rating. Optional service/amenities ratings and comment now sit in an Add details disclosure. QR check-in and duplicate-review checks are unchanged. This does not turn a vacation survey into an establishment review.

Verification: 18 checks in `deploy/check-quick-exit-survey.php` passed against the isolated current production app. They cover rendering, required questions, spending choices, minimum/full submissions, invalid inputs, restored answers, QR gating, and review disclosures. All test survey and check-in records were rolled back. Public HTTP checks confirmed the new survey content and both CSS endpoints. No complete browser visual inspection was performed.

Production backup: `/var/backups/exploredvo/quick-survey-20261115-135448/`. Exact four-file diff: `deploy/quick-exit-survey-2026-11-15.patch`. Publishing script: `deploy/publish-quick-survey-2026-11-15.sh` (one-time, guarded against changed originals).

The local exit-survey template and CSS matched the production baseline and were updated. The local review partial predates production's optional aspect ratings, so its production changes are preserved in the exact patch rather than overwriting the older checkout. The new quick-review stylesheet is present locally. Do not deploy the entire dirty checkout.

To roll back, restore the three existing files from the backup and clear compiled views. The additional quick-review stylesheet is harmless if retained after restoring the template.
