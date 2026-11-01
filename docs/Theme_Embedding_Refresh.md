# Theme embedding refresh — 2026-11-01

The grouping algorithm from the pre-PR #179 planner remains unchanged. It needs a stored vector for every selected stop. A newly added destination without a vector causes the entire trip's grouping step to return no theme result.

The checked live catalogue contained 85 public destinations, of which 48 were absent from the shipped vector export. The refresh adds those 48 and another 31 missing existing local catalogue records. The export now contains 121 destination vectors. All previous 42 destination vectors and all eight interest vectors are preserved.

New vectors were generated locally with the original `nomic-embed-text` model, 768 dimensions and the existing `search_document: ` prefix. Three recovered unchanged inputs from the supplied earlier public catalogue dump reproduced the original vectors with cosine similarity above 0.99998. No fake or randomly generated vectors were used.

`database/data/embedding-refresh-2026-11-01.json` records the public source pages, exact embedded input, added slugs, old-vector hashes, compatibility measurements and the covered live snapshot. Public rendered destination names, types, category tags, amenities and descriptions supply the live input. Additional local-only input comes from the existing local catalogue. Sample names remain sample names; this refresh changes no accreditation, place data, ranking, routing, rental-car suggestions or resort classifications.

After deploying the refreshed main branch, verify the embedding import on the server:

```bash
cd /var/www/exploredvo
sudo -u www-data php artisan embeddings:import
sudo -u www-data php artisan embeddings:coverage --json
```

The coverage command is read-only and returns failure if a public destination lacks a usable vector or dimensions are inconsistent. New catalogue records will require their own genuine embeddings; re-importing an older file cannot create missing vectors.

Generate a new itinerary after the import. Existing saved itineraries keep their old theme result. The grouping badge still appears only when regrouping improves similarity within the existing distance allowance and the normal schedule guard keeps the themed plan. Complete coverage enables grouping; it does not promise that every trip should be regrouped.
