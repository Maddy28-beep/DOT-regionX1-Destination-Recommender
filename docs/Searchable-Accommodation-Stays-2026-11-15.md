# Search actual accommodations by typing

The stay picker now has a visible text input that filters accommodation names and addresses by typed words, including city or area. Matching is case insensitive and ignores common combining accents. Users choose a result to save its existing directory ID, or choose Other to enter their actual accommodation manually. Friends/family and no overnight stay remain available.

The selected property name/address remains visible. Editing it clears the old ID; nonempty search text must be resolved by selecting a result or Other, so an unmatched query is not silently discarded or treated as a verified property. Empty answers remain optional. Keyboard arrows, Enter, Escape, pointer selection, labels and combobox/listbox semantics are supported. Suggestions scroll inside an inline panel beneath the input, keeping typed text visible instead of overlaying the input. Added stays have independent controls and preserve the existing five-stay limit.

The shared site dropdown explicitly skips these selects. Without JavaScript the original native select and manual fields still work. No dependencies were added to the application, and backend validation/storage/reporting are unchanged.

Verified with DOM interaction assertions in `deploy/check-searchable-stays.cjs` using a temporary jsdom 26 installation outside the repository, including coexistence with the existing shared dropdown. Run with `node deploy/check-searchable-stays.cjs <path-to-jsdom>`. The existing 27 stay checks and 18 quick-feedback checks passed in the isolated server app, with all QA writes rolled back. JavaScript syntax and diff whitespace checks passed. Public HTTP verified the opt-in markup, updated hint and exact deployed script. Full visual browser QA was not performed.

Four live files were backed up and original hashes checked before deployment: `public/js/survey-stays.js`, `public/css/exit-survey.css`, and the two `survey-stay*` partials. Backup: `/var/backups/exploredvo/searchable-stays-20261115-152121/`. Restore those four files and clear compiled views to roll back. No database migration or data changes were needed.
