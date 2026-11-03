# Theme embedding refresh — 2026-11-01

The grouping algorithm from the pre-PR #179 planner remains unchanged. It needs a stored vector for every selected stop. A newly added destination without a vector causes the entire trip's grouping step to return no theme result.

The checked live catalogue contained 85 public destinations, of which 48 were absent from the shipped vector export. The refresh adds those 48 and another 31 missing existing local catalogue records. The export now contains 121 destination vectors. All previous 42 destination vectors and all eight interest vectors are preserved.

New vectors were generated locally with the original `nomic-embed-text` model, 768 dimensions and the existing `search_document: ` prefix. Three recovered unchanged inputs from the supplied earlier public catalogue dump reproduced the original vectors with cosine similarity above 0.99998. No fake or randomly generated vectors were used.

`database/data/embedding-refresh-2026-11-01.json` records the public source pages, exact embedded input, added slugs, old-vector hashes, compatibility measurements and the covered live snapshot. Public rendered destination names, types, category tags, amenities and descriptions supply the live input. Additional local-only input comes from the existing local catalogue. Sample names remain sample names; this refresh changes no accreditation, place data, ranking, routing, rental-car suggestions or resort classifications.

Since the deploy script no longer imports embeddings by default (see `deploy/DEPLOYING.md`), load them either by deploying with `.\deploy\deploy.ps1 -RefreshEmbeddings` or by hand. After deploying the refreshed main branch, verify the embedding import on the server:

```bash
cd /var/www/exploredvo
sudo -u www-data php artisan embeddings:import
sudo -u www-data php artisan embeddings:coverage --json
```

The coverage command is read-only and returns failure if a public destination lacks a usable vector or dimensions are inconsistent. New catalogue records will require their own genuine embeddings; re-importing an older file cannot create missing vectors.

Generate a new itinerary after the import. Existing saved itineraries keep their old theme result. The grouping badge still appears only when regrouping improves similarity within the existing distance allowance and the normal schedule guard keeps the themed plan. Complete coverage enables grouping; it does not promise that every trip should be regrouped.

## Phase 4 correction (2026-11-02)

The Phase 4 package (`f9390ec`, shipped in PR #185 and deployed in
`b7603d3`) grew the export from 121 to 151 destination vectors: 54 newly
generated, 97 carried over. Of the carried vectors, 62 are byte-identical
to this refresh's package and 35 were intentionally regenerated from
current source text because their embedded text had gone stale — exactly
the 35 slugs that the original `preserved_embeddings_sha256` list (the
42 pre-refresh vectors) no longer matched, which failed
`RefreshedEmbeddingCoverageTest::test_existing_destination_and_interest_vectors_are_preserved`.

The invariant is source identity, not frozen bytes: a vector must
correspond to its listing's current text (`text_hash`), and byte
preservation is only expected where the text did not change. The
manifest now reflects that: `preserved_embeddings_sha256` lists the 62
independently verified byte-identical vectors (identical rows in both
shipped packages), `phase4_refreshed_slugs` records the 35 regenerated
slugs, and the eight interest vectors remain preserved unchanged. No
vector was modified by this correction; only the manifest's record of
which vectors are preserved was brought up to date.
