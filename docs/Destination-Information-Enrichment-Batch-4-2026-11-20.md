# Destination information enrichment — batch 4

Researched across 19–20 November 2026 and applied locally on 20 November 2026. This pass started with 91 existing listings without sourced amenities. It added **50 amenity tags and 30 detail-field updates across 36 existing listings**.

Of the initial cohort, **26 listings received amenities**, **10 received other detail updates**, and **55 received no new confirmed update**. **65 listings still have unconfirmed amenities**. No destination was added, renamed, merged, published or removed.

## Reviewed import and progress index

- Import manifest: `database/data/destination-enrichment-batch4-2026-11-20.json`.
- Initial 91-listing progress index: `database/data/destination-amenity-review-2026-11-20.json`.
- Current rolling gap audit: `database/data/destination-enrichment-pending-2026-11-19.json`; its audit date is now 20 November. The filename is retained for references in earlier reports.

Each edit in the manifest includes its expected previous value, source URL and reason. Amenities include evidence and status. The progress index records actual changes and outstanding gaps; it does not claim every unresolved facility has been confirmed with an operator.

## Applied information and sources

| Existing listing | Added or clarified information | Sources |
| --- | --- | --- |
| Belviz Farm | Durian tasting tours (reported) | [yearofthedurian.com](https://www.yearofthedurian.com/2023/11/belviz-farms-all-year-davao-durian-buffet.html) |
| Bukid Amara Tourism Agriventures Corporation | description. Educational farm tours | [tieza.gov.ph](https://tieza.gov.ph/tieza-expands-its-portfolio-with-seven-newly-registered-business-enterprises/) |
| Damosa Land Inc. / Agriya Naturetainment | Educational farm tours; Picnic area (reported) | [experiencedavao.com](https://www.experiencedavao.com/attractions/agriya-naturetainment), [sunstar.com.ph](https://www.sunstar.com.ph/davao/lifestyle/manlalakbay-biyaheng-davnor) |
| JKM Mini Zoo | description | [jkmsons.com.ph](https://jkmsons.com.ph/) |
| IMIN The Philippine-Japan Historical Museum | visitor website, description | [davao.ph.emb-japan.go.jp](https://www.davao.ph.emb-japan.go.jp/itpr_ja/11_000001_00467.html) |
| Elysia Wellness Spa | Spa & massage (reported) | [sunstar.com.ph](https://www.sunstar.com.ph/davao/weekend/the-women-behind-elysia-wellness-spa) |
| San Pedro Cathedral | visitor website, address | [sanpedrocathedral.com](https://www.sanpedrocathedral.com/) |
| Magsaysay Park | Amphitheater | [davaocity.gov.ph](https://davaocity.gov.ph/cooperative/davao-city-opens-coop-month/) |
| Isla Reta | address. Day-use cottages (reported); Campsites (reported); Restroom (reported); Showers (reported) | [meanttogo.com](https://meanttogo.com/isla-reta-weekend-getaway/), [cebudavao.com](https://cebudavao.com/travel/tips/how-to-go-to-isla-reta-beach-resort/) |
| Coral Garden | Guided boat visits by arrangement | [hofgoreiresortdavao.com](https://hofgoreiresortdavao.com/explore/island-hopping/) |
| Giant Clam Sanctuary | address. Guided conservation tours | [dnsc.edu.ph](https://dnsc.edu.ph/taklobo-tours/) |
| Malipano Island | visitor website, description. Villas (villa guests); Restaurant (villa guests); Swimming pool (villa guests); Bridge to De la Paz (villa guests); Private events by arrangement | [pearlfarmresort.com](https://pearlfarmresort.com/isla-malipano/), [pearlfarmresort.com](https://pearlfarmresort.com/isla-malipano/events/) |
| Angels Cove | Guided boat visits by arrangement | [hofgoreiresortdavao.com](https://hofgoreiresortdavao.com/explore/island-hopping/) |
| National Museum of the Philippines - Davao | visitor website, address, published schedule. Passenger elevator (reported) | [reservation.nationalmuseum.gov.ph](https://reservation.nationalmuseum.gov.ph/reservation/book/region-museum), [weblinks.nationalmuseum.gov.ph](https://weblinks.nationalmuseum.gov.ph/wp-content/uploads/2025/11/24092537/Bidding-Documents-Per-Lot-PM-of-Elevators.docx.pdf) |
| Osmeña Park | Playground (reported) | [pna.gov.ph](https://www.pna.gov.ph/articles/1038329) |
| Uraya's Peak [SAMPLE] | address. Restaurant (reported); Overnight lodging (reported); Walking trails (reported); Guided tours (reported) | [sunstar.com.ph](https://www.sunstar.com.ph/davao/lifestyle/manlalakbay-biyaheng-davnor) |
| Malita Dives & Marine Sanctuary [SAMPLE] | description. Overnight lodging by reservation (reported); Restroom (reserved guests) (reported); Karaoke (reserved guests) (reported) | [sunstar.com.ph](https://www.sunstar.com.ph/davao/lifestyle/alcantara-malita-dives-resort-in-a-sanctuary) |
| Lake Leonard [SAMPLE] | published Facebook contact, visitor website | [davaodeoro.gov.ph](https://davaodeoro.gov.ph/tourist-circuit-wellness-loop/) |
| Baganga Sunrise Boulevard | Restaurant (reported); Playground (reported); Day-use cottages (reported) | [sunstar.com.ph](https://www.sunstar.com.ph/amp/story/more-articles/sweet-escape-exploring-baganga-davao-oriental) |
| Pyalitan Falls | visitor website, address, description | [davaodeoro.gov.ph](https://davaodeoro.gov.ph/tourist-circuit-wellness-loop/) |
| Mipangi Hilltop | visitor website, suggested visit time. Hiking route (reported) | [nabunturan.gov.ph](https://www.nabunturan.gov.ph/tourist-destination) |
| Davao City Coastal Road Public Parks | Designated pedestrian areas | [davaocity.gov.ph](https://davaocity.gov.ph/transportation-planning-traffic-management/cttmo-to-ban-skating-allow-bikers-and-joggers-only-in-designated-areas-as-coastal-road-opens-saturday/) |
| Sicao Falls | Trekking route (reported) | [meanttogo.com](https://meanttogo.com/sicao-falls-trekking-guide/) |
| Fyingi Sol Cave | visitor website | [experiencedavao.com](https://www.experiencedavao.com/activities/fyingi-sol-cave) |
| Daya Farm & Adventure | description. Swimming pool (confirm guest access) (reported); Parking (reported); Cafe (confirm guest access) (reported); Overnight lodging (reported); Grilling areas (confirm guest access) (reported); Meeting rooms by arrangement (reported) | [expedia.com](https://www.expedia.com/Banaybanay-Hotels-DAYA-FARM-AND-ADVENTURE.h101341542.Hotel-Information) |
| DECA Wakeboard Park Davao | Cable wakeboarding (reported); Restaurant (reported) | [waketricks.com](https://waketricks.com/parks/p/decawake-davao-cable-park) |
| Mount Fortune View | Viewing deck (reported); Day-use cottages (reported); Campsites (reported); Restroom (reported) | [youtube.com](https://www.youtube.com/watch?v=FV1gHJsMS4c) |
| Langan Falls | visitor website | [mountapogeopark.com](https://mountapogeopark.com/geosite/langan-falls/) |
| Sibulan River Tubing | Guided river tubing (reported) | [chinitowanders.wordpress.com](https://chinitowanders.wordpress.com/2016/06/19/davao-del-sur-the-thrill-with-sta-cruz-river-tubing/) |
| Sub'on Cave & Underground Stream [SAMPLE] | Community-guided cave visits (reported) | [pna.gov.ph](https://www.pna.gov.ph/articles/1174777) |
| Davao Chinatown [SAMPLE] | visitor website. City heritage walks (confirm schedule) | [davaocity.gov.ph](https://davaocity.gov.ph/tourism/bound-to-the-friendship-arch-davao-city-chinatown-walk-boosts-tourism-and-cultural-heritage/) |
| Davao Occidental Provincial Capitol Complex [SAMPLE] | visitor website. Provincial tourism office | [davaooccidental.gov.ph](https://davaooccidental.gov.ph/provincial-tourism-culture-and-arts-office/) |
| Magongawon Falls — Forest Trail | Stepped forest path (reported) | [thetravelintern.com](https://thetravelintern.com/davao-guide/) |
| The Grape Valley | visitor website | [nabunturan.gov.ph](https://www.nabunturan.gov.ph/tourist-destination) |
| Battle of Ising Memorial | visitor website | [mirror.pia.gov.ph](https://mirror.pia.gov.ph/news/2023/04/07/pvao-assists-lgu-in-making-ising-monument-a-military-shrine) |
| Davao City Hall | visitor website | [davaocity.gov.ph](https://davaocity.gov.ph/tourism/davao-city-hall-postage-stamp-unveiled/) |

## Evidence and access distinctions

- Older or secondary facility accounts carry a visible **(reported)** label. Current availability still requires confirmation. A reported facility is not a guarantee of current operation.
- Malipano facilities are labeled for villa guests, and its description explains restricted access. No facilities from Pearl Farm's other island were copied onto Malipano.
- Coral Garden and Angels Cove have guided boat-visit services by arrangement; no onshore toilets, restaurants or cottages were inferred at the reefs. A multi-stop cruise duration was not copied into any site's visit-duration field.
- Daya Farm's provider-listed facilities are reported, with day-visitor access to be confirmed. In-room Wi-Fi and air conditioning were not presented as general day-visitor amenities. Pool hours, check-in times and nightly prices were not used as destination hours or fees.
- Bukid Amara evidence explicitly identifies the Davao corporation; Quezon facilities were not copied. TIEZA registration did not alter any DOT accreditation field.
- The National Museum's FY2026 inventory identifies a passenger elevator at Davao (PDF page 36). The six in its table is a stop count, not a count of elevators. The reported label does not certify present serviceability or a fully step-free visit.
- Street and hiking routes are labeled as pedestrian, stepped, hiking or trekking routes as appropriate; no paving, accessibility or maintenance guarantee was inferred.
- Historical/reference-only rows and legacy aliases keep their previous publication status. These counts describe database listings, which include some legacy names with a [SAMPLE] suffix, rather than a count of unique newly discovered destinations.

## Information held back

- **Isla- Agri Ventures Inc**: No branch-matched visitor facilities confirmed. Must not use Isla Reta or other Tagpopongan resorts by name similarity.
- **Elysia Wellness Spa (Quimpo); Elysia Wellness Spa (Agdao)**: Obrero evidence does not confirm these branches; no spa service tags copied between branches.
- **Kaputian Beach**: Current charter PDF is too large for web extraction. Older indexed room-rate tables were not treated as current facility/access confirmation. [Evidence](https://samalcity.gov.ph/images/pdf/IGACOS_CC_2025.pdf).
- **Pusan Point; Aliwagwag Falls**: Recent restoration reporting warrants current operator confirmation before assigning old infrastructure claims. [Evidence](https://mindanao.politiko.com.ph/gov-nelson-l-dayanghirang-requests-tieza-inspection-of-aliwagwag-falls-pusan-point-for-restoration/).
- **Island Buenavista**: Previously reviewed operator site remains an unresolved availability/source issue; no historical facilities copied.
- **Lake Leonard [SAMPLE]; Pyalitan Falls**: Cottages, toilet and picnic-ground paragraph belongs to Tagbibinta Falls. No lake bathing or total visit duration inferred. [Evidence](https://davaodeoro.gov.ph/tourist-circuit-wellness-loop/).
- **Divine Mercy Shrine (Malita); Sanctuario de Santo Rosario**: Mati shrine and Quezon City Santo Rosario results do not establish facilities at the Malita listings.
- **Fabian’s Peak**: Araibo/Pantukan and Maragusan references remain conflicting; no different venue’s facilities copied.
- **Oak Island [SAMPLE]; Burias Islet; Crocodile Hill**: US Oak Island, Masbate Burias and foreign Crocodile Hill results rejected as unrelated destinations.
- **Poo Sandbar; Big Ligid Island; Sigaboy Island; Diaz Island; Olanivan Island; Tuke Makil / Tuke Nunsol Marine Cove**: Marine place identification does not confirm visitor toilets, cottages or shore infrastructure; facilities remain unconfirmed.
- **Carmen Mangrove Sanctuary & Boardwalk [SAMPLE]**: Del Carmen/Siargao results rejected; exact Davao venue identity and its facilities still need confirmation.
- **National Museum of the Philippines Davao [SAMPLE]; San Pedro Cathedral [SAMPLE]; San Salvador del Mundo Church (Caraga Church) [SAMPLE]**: Legacy duplicate/alias records left unchanged; do not inflate newly researched unique venues by copying details automatically.
- **Energy Park; New City Hall of Tagum**: Earlier charter PDF was unavailable; no facilities inferred from construction plans or snippets.

Other search leads also remained unconfirmed: foreign Oak Island/Burias/Crocodile Hill results, planned construction, unspecified natural-site facilities, inconsistent place identities and unavailable documents were not used to fill required-looking blanks. Built amenities are not necessarily appropriate for every reef, sandbar, shrine or forest site.

## Validation

- Preview and local application agreed: **36 listings, 30 field values, 50 amenities**.
- Imported values and amenity tags checked against the manifest: passed.
- Every non-manifest destination field compared against the before snapshot for all **183 real-named active listings**: **zero unexpected changes**.
- All fee minima/maxima, price tiers, visit durations, coordinates, photo paths, identity, publication, accreditation and planner verification fields remained unchanged. Unpublished values remain unconfirmed.
- All pre-existing amenity tags preserved.
- Fingerprints unchanged for photos, accreditation records, itineraries, itinerary items, itinerary matches, restaurants, accommodations, souvenir centers and packages.
- **36 of 36** affected detail routes matched their existing public/hidden status; amenity labels rendered on applicable public detail pages. This was a server-rendered route check, not a visual browser review.
- Focused importer and detail/card regression suite: **24 tests passed, 707 assertions**.
- No listing or planner application code changed. No commit, push or deployment performed.

## Current outstanding gaps

| Unconfirmed information | Listings |
| --- | ---: |
| Amenities | 65 |
| Opening hours | 108 |
| Entrance-fee range | 116 |
| Price tier | 115 |
| Visit duration | 120 |
| Coordinates | 25 |
| Website URL | 142 |

Unknown means unconfirmed, not free, closed, absent or inaccessible. No fees or durations were estimated in this pass.
