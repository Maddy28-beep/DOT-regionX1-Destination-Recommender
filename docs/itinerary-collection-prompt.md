# Prompt: coding published Davao itineraries into baskets

Paste this into an AI assistant that can browse the web. Check the output yourself before importing it (see "After you get the CSV").

```text
ROLE
You are a research assistant helping with a capstone on Davao Region (Philippines) tourism. You will read PUBLISHED travel itineraries on the web and record which PLACES appear on each day. The result is a dataset of "places visited together on the same day".

TASK
Find as many different published itineraries as you can (target 100 or more, from as many different websites as possible) that clearly cover places in DAVAO REGION: Davao City, Davao del Norte (including Island Garden City of Samal), Davao del Sur, Davao de Oro, Davao Oriental, Davao Occidental.

WHERE TO LOOK
1. Tour operators and travel agencies in Davao Region: their own websites and public Facebook pages with day-by-day itineraries or packages.
2. Travel blogs and travel sites. Search for example: "Davao 3 days 2 nights itinerary", "Davao day tour itinerary", "Samal Island itinerary", "Mount Apo itinerary", "Mati Davao Oriental itinerary", "Davao food trip itinerary".
3. DOT Region XI and other official tourism pages.
Prefer itineraries published or updated in 2022 or later.

CURRENT FOCUS (batch 4 and later)
The data so far has plenty of attractions but too few NAMED HOTELS tied to the places they are near. So now:
- Look first for itineraries and packages that name the hotel, resort or inn for every night: tour operator packages (2D1N, 3D2N, 4D3N), "where to stay in Davao" guides with a sample itinerary, and honeymoon or family itineraries.
- For each night record the hotel on that day, plus the day's attractions, meals and shops as before.
- Concentrate on Davao City, Samal Island, Davao del Sur (Digos, Mount Apo), and the Mati and Dahican circuit. Skip Maragusan, Davao Occidental and other areas I have already covered thinly.
- Do not use more than two itineraries from the same website in one batch, and do not use any page listed under "ALREADY COLLECTED".

STRICT RULES
- Open and read each page. If you cannot open it, do NOT include it; list it at the end under "could not open".
- Take only itineraries that clearly name places in Davao Region, and that are laid out by day (or are a single day trip). Skip vague "top 10 things to do" lists that are not an itinerary.
- PREFER itineraries that also name where to stay and where to eat. Operator packages and multi-day plans (2D1N, 3D2N and longer) usually do.
- One page may hold several itineraries (for example a 2-day and a 3-day plan): give each its own itinerary_id.
- Record the exact web address (URL) and today's date as date_accessed.
- Record ONLY the names of places and the day each appears on. Do NOT copy, quote or paraphrase the page's text, descriptions, prices or photos.
- The same itinerary republished on another site counts once (use the original).
- STAYS, MEALS AND SHOPS ARE REQUIRED DATA, NOT OPTIONAL. For every day, also record:
  * the hotel, resort or inn named for that night (use place_type accommodation, on the day of that night; if the plan names one hotel for the whole trip, repeat it on each day it applies);
  * every restaurant, cafe or named meal stop (place_type restaurant);
  * every shop, market or souvenir stop (place_type souvenir).
  Write each name exactly as printed. If an itinerary only says "lunch at a local restaurant" or "overnight in a hotel" without a name, do not make one up: leave it out.
- Do not list travel infrastructure: no airports, bus or ferry terminals, ports or wharfs, city hall, hospitals, and no bare area names such as "Davao" or "Davao City". DO list hotels/resorts, restaurants, shops and attractions.
- Never invent places or itineraries. If you find fewer than 100, say the real number.

PLACES TO MATCH AGAINST
Destinations: Apo Golf and Country Club | Belviz Farm | Bemwa Farm Fresh Inc. | Bukid Amara Tourism Agriventures Corporation | Dahican Beach | Damosa Land Inc. / Agriya Naturetainment | Davao Crocodile Park | Eden Nature Park | Elysia Wellness Spa | IMIN The Philippine-Japan Historical Museum | Isla-Agri Ventures Inc | JKM Mini Zoo | Lao Integrated Farms | Malagos Garden Resort | Mount Apo Natural Park | People's Park | Philippine Eagle Center | Rancho Palos Verdes Golf & Sports Club | SMX Convention Center Davao | Samal Island | South Pacific Davao Country Club
Also match these: Museo Dabawenyo | San Pedro Cathedral | Magsaysay Park | Roxas Night Market | Japanese Tunnel | Bankerohan Public Market | Shrine of the Holy Infant Jesus of Prague | Hagimit Falls | Talikud Island | Isla Reta | Coral Garden | Aliwagwag Falls | Subangan Museum | San Salvador del Mundo Church | Pusan Point | Vanishing Island | Wishing Island | Sabang Cliff | Kaputian Beach | Giant Clam Sanctuary | Malipano Island | Monfort Bat Cave | Dayang Beach | Diaz Island | Angels Cove
Shops: Aldevinco Shopping Center | Apo Ni Lola Durian Delicacies | Davao Local Products & Souvenir Center | Kadayawan Souvenir Shop | Godel Agriventures Inc | Ocean Bx
Packages: Dahican Beach Surf & Chill Package | Davao City Cultural Heritage Tour | Eden Nature Park Day Adventure | Mount Apo 3-Day Summit Trek | Samal Island Hopping Day Tour
Restaurants, hotels and resorts: write the name exactly as it appears in the itinerary, and leave matched_place empty. They are matched to my database afterwards.
If a place is NOT on these lists, still record it, exactly as written, and leave matched_place empty.

ALREADY COLLECTED (do NOT use these pages again)
https://meanttogo.com/mati-travel-guide-things-to-do-sample-itinerary-budget
https://twomonkeystravelgroup.com/heavens-stairway-aliwagwag-falls-davao
https://www.thepoortraveler.net/2011/05/davao-highland-adventure-summary-expenses
https://www.ambot-ah.com/davao-itinerary-3-days
https://budgetarianexplorer.wordpress.com/2018/09/01/davao-the-safe-haven-3-days-2-nights-budget-itinerary
https://lorismaeshielda.com/samal-davao-del-norte-travel-guide-and-sample-itinerary
https://realbreezedavaotours.com/3d2n-davao-tour-package
https://islanddiariesph.com/product/samal-island-hopping-joiner-tour
https://misskhae.com/mount-apo-hiking-guide
https://awanderfulsole.com/pusan-point-travel-guide
https://distinctnomad.blogspot.com/2013/08/wow-davao.html
https://www.tpb.gov.ph/wp-content/uploads/2025/02/QF-MPRO-08-Itinerary-Form-Rev-02-DTIP-2025-Davao.pdf
https://tpb.gov.ph/wp-content/uploads/2025/02/TPB-RFQ-2025-02-051-Tour-Op-HK-SAR-2nd-posting.pdf
https://www.viewtifultravels.com/product-page/davao-tour
https://www.discovermnl.com.ph/davao-itinerary
https://regenttravelph.com/student-tour-lists/educational-student-tours-philippines
https://www.tpb.gov.ph/wp-content/uploads/2025/07/TPB-ITB-NO.-2025-040_Itinerary-for-Lot-2-Davao.pdf
https://www.gojavalava.com/davaotours
https://thequeensescape.com/ultimate-diy-samal-island-travel-guide-2023
https://thequeensescape.com/2023-mati-davao-oriental-diy-travel-guide-itinerarybudget
https://joansfootprints.com/2026/05/28/travel-guide-to-davao-city-samal-island-2020-diy-itinerary-budget
https://www.rjdexplorer.com/mati-city-tour-davao-oriental
https://dailygaelley.com/2025/10/06/mati-24-hour-travel-guide
https://realbreezedavaotours.com/3d2n-davao-tour-with-island-hopping-package
https://www.kingtolentino.com/blog/davao-travel-guide
https://www.gamintraveler.com/2023/11/10/2-days-samal-island-itinerary
https://leezgo.ph/davao-occidental-the-quiet-beauty-of-the-south-hidden-gems-itinerary-and-how-to-get-there
https://iwannatravel.com.sg/philippines/3d2n-mount-apo-summit-trek-santa-cruz-traverse-bansalan-trail
https://www.sunstar.com.ph/amp/story/davao/feature/maragusan-road-trip-a-family-friendly-escape-in-davao-de-oro
https://meanttogo.com/maragusan-weekend-travel-guide-attractions-to-visit-accommodation-budget
https://www.rjdexplorer.com/things-to-do-in-maragusan-davao-de-oro
https://islanddiariesph.com/product/maragusan-joiner-tour
https://islanddiariesph.com/product/samal-inland-joiner-tour
https://islanddiariesph.com/product/countryside-joiner-tour
https://easytours.com.ph/domestic-tours/davao-tour-package
https://www.tripzilla.ph/davao-samal-island-itinerary/8985
https://agitopassim.wordpress.com/2013/10/19/4d3n-davao-planned-itinerary
https://agitopassim.wordpress.com/2013/10/27/4d3n-davao-actual-itinerary-and-summary-of-expenses
https://realbreezedavaotours.com/2d1n-mati-tour
https://www.wazzup.ph/exploring-the-beauty-of-davao-a-memorable-adventure
https://traveledictorian.com/davao-travel-guide-an-excellent-guide-to-the-world
https://www.e-philippines.com.ph/philippines-travel-package/3d2n-explore-davao-tour-package-f
https://www.traveloka.com/en-en/explore/destination/davao-itinerary-4-days/1003559
https://davaotouristapp.com
https://www.tripzilla.ph/exploring-davao-city-less-php-7000/11695
https://fliphtml5.com/welwo/aipy/Cultural_Immersion_in_Davao_City_(Itinerary)
https://www.gamintraveler.com/2023/11/09/3-days-davao-itinerary
https://wayph.com/travel-guide/davao-city-travel-guide
https://lexicalcrown.blogspot.com/2025/12/our-first-family-trip-to-davao-city.html
https://soulfullescapes.wordpress.com/2024/04/02/davao-with-family
https://awanderfulsole.com/caraga-davao-oriental-travel-guide-budget-itinerary

OUTPUT FORMAT
A CSV with exactly these columns, one row per place per day:
itinerary_id,source_url,source_type,date_accessed,trip_length,day,place_name_as_written,matched_place,place_type,match_confidence

- itinerary_id: continue from I61 (I61, I62, I63 ...). The same id on every row of one itinerary.
- source_type: operator / blog / dot / agency / other
- trip_length: for example 3D2N, 2D1N, day trip
- day: 1, 2, 3 ... (the day the place appears; always fill this in)
- place_name_as_written: as it appears on the page
- matched_place: the matching name from the lists above, or EMPTY if it is not on the lists
- place_type: destination / restaurant / accommodation / souvenir / package / other
- match_confidence: high / medium / low, ONLY when matched_place is filled; otherwise leave EMPTY

Quote any field that contains a comma. Use plain straight apostrophes.

Work in batches of about 20 itineraries. After each batch tell me the running totals (itineraries, distinct websites) and wait for me to say "continue".
At the end list: pages you could not open, any itinerary you were unsure about, and how many itineraries named at least one hotel, restaurant or shop.
```

## After you get the CSV

1. Open 2 or 3 of the URLs and compare them with their rows. An assistant can misread or invent entries.
2. Delete any itinerary you cannot verify.
3. Dry run, which saves nothing and lists the most-mentioned places with no match:
   `php artisan itineraries:import path/to/file.csv --dry-run`
4. If the report looks right, run it without `--dry-run`. Running the same file twice adds nothing.

Hotel, restaurant and shop names that are already in the catalogue are matched automatically (the report lists the ones that are not). They matter because the itinerary generator uses these pairs to choose a stay, a lunch and a shop next to each destination. One hotel appearing in many itineraries is what builds a usable "this place goes with that hotel" rule.

Baskets are saved with `data_source = itinerary`: one per itinerary day, with at least two places from the catalogue. They are Apriori transactions only, never counted as survey respondents. The Association Rules page has a source switch (all, tourist surveys, published itineraries, simulated demo) so each can be read on its own.
