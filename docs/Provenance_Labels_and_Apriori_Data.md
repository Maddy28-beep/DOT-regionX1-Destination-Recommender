# Accreditation labels, sample data and the Apriori data

> **Status (31 Oct 2026): implemented on the branch `feature/data-provenance-labels` and opened as a pull request for review. NOT approved for merging. NOT deployed.**
> Kept in its own file (not in the `.docx` implementation notes) so it cannot conflict with the stay-continuity pull request, which edits those documents. It should be folded into the implementation notes after both are decided.

## 1. What a listing is labelled

Every listing shows exactly one label, decided from its accreditation records (`accreditation_records`, using the existing `is_placeholder` flag; **no new column**):

| Label | Rule | Meaning |
|---|---|---|
| **DOT Accredited** | at least one record that is not a placeholder **and** carries an accreditation number | official DOT record |
| **Sample Data** | it has records and every one is a placeholder (our number, not DOT's) | temporary demonstration record |
| **Not Yet Verified** | no accreditation record at all, or only records without a number | accreditation cannot be confirmed |

A missing record is never read as official. The rule lives in one place, `App\Support\SampleData` (`status()`, `label()`, `title()`), and is used by the catalogue cards, the destination, stay, dining, souvenir, tour operator and package pages, the chatbot and the planner's provenance panel.

"Verified" here means *a real record with an accreditation number*. The official records in the database carry no `verified_at` or `verified_by` value, so those columns cannot be the test. If DOT later supplies a verification date, the rule can be tightened in the one place above.

## 2. What the labels do not do

They do not change eligibility. Sample and Not Yet Verified listings stay in the Content-Based Recommendation, Apriori, itinerary generation and geographic sequencing, because those use `is_accredited` and `archived_at` only. No recommendation code mentions provenance (a test reads those files and fails if that changes). No recommendation algorithm, scoring weight, distance threshold, accreditation value, expiry date, grace setting, price or existing listing record was changed.

How this was verified: `SampleParticipationTest` plans the same catalogue with every record official, every record sample, no records, and a mixed half-and-half, and requires identical rankings (every factor), stops, order and stays; a control test shows the comparison does detect a real difference. Against a copy of the production data (30 Oct 2026): 37 destinations are eligible (12 official, 25 sample); in nine traveller profiles samples were 59% of the ranked candidates and 76% of the top five; the audit itineraries placed 69% sample stops.

## 3. The Apriori data is simulated testing data

**All 456 survey transactions in the database, and every visit recorded in them (1,426 in total, of which 1,027 are visits to destinations), are simulated for testing.** Their source is `demo`, from one generator run (batch `demo-samples-2026-11`). There are no survey transactions from real tourists yet.

The association rules mined from them (449 rules at support of at least 2 and confidence of at least 0.1 on the copy of the production data checked on 30 October 2026) demonstrate that the method works. They are **not actual tourist transactions, not verified visitor behaviour and not real-world tourism patterns**, and must not be reported or presented as findings about tourism in the Davao Region. This holds for sample and official destinations alike, and for every Apriori suggestion in the itinerary results.

The itinerary page already labels the count ("simulated for demonstration") and the admin rules page separates simulated surveys from real ones. This note is the explicit statement for the documentation and the defense.

## 4. Test data, not verified DOT information

The sample records are a temporary beta-testing arrangement and must eventually be replaced with verified DOT records. When a real record is added to a sample listing (the placeholder may stay), its label becomes DOT Accredited automatically. Existing accreditation values, expiry dates and prices were not changed.

## 5. Wording that was corrected

The home page said "Verified DOT-accredited spots", "a DOT-verified must-see" (all six featured destinations are samples), "connects travelers directly with DOT-verified destinations" and similar; the plan chooser, two helper texts, the visit-card link and the site description (search engines and link previews) said "DOT-accredited" for everything. They now describe what the record is. The legal pages and the partner registration page were deliberately left unchanged (no specific factual or compliance issue was identified).
