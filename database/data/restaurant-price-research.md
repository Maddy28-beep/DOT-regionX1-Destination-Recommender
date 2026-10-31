# Restaurant price research (web, 2026-10-20)

Scope: the 44 dining listings in the database. Searched the better-known ones; the small provincial eateries
(Davao de Oro, Davao Oriental, Davao Occidental) were not searched because there is almost nothing about them online.
**Nothing here has been written to the database.** The `restaurants` table has only `price_tier` (no peso figure).

Confidence: A = a current-looking peso figure for this very place; B = dated, from another branch, or from a delivery app;
C = only a qualitative remark ("pricey", "affordable"); none = nothing found.

| Listing (DB id) | Current tier in DB | What the web says | Conf. | Source |
|---|---|---|---|---|
| Vikings (43), SM Lanang | blank | Buffet P888 weekday, P988 weekend/holiday, plus 5% service charge (undated). Older: P588 lunch / P788 dinner (SunStar, undated); P638 to P1,138 across branches (MoneyMax, 2021). | B | Wanderlog, SunStar, MoneyMax |
| Tsuru (38, 39, 40, 41, 42) | Mid-range on 2, blank on 3 | Menu aggregator for the Camus branch: kimchi P153, gyoza P259, seafood shio ramen P442, beef teppanyaki P678, sashimi platter P731. Reviewers: about P300 to P500 per person before extras, "expensive" for one vegetarian reviewer. | B | menuweb, Wanderlog, HappyCow |
| Nonki Japanese (29), F. Torres | Mid-range | Delivery prices at two other Davao branches: maki P385 to P595, gyoza P295, chicken teriyaki P405, Angus steak P1,845. Reviewers: "a bit pricey", one listing at the highest price level. | B | Foodpanda (2 branches), Wanderlog |
| Blue Post Boiling Crabs & Shrimp (3) | Mid-range | Sold by weight at market price. One reviewer: rice, a small crab and a beer came to about P1,300 per person ("expensive"). Older articles: shrimp P573/lb, Cajun prawns P850/500g. | B | Trip.com, SunStar, travel blog |
| Marina Tuna Restaurant (1) and Marinatuna (27) | Mid-range / blank | No peso prices. Reviews are mixed ("higher side", "reasonable", "affordable"). Tuna belly, tail, jaw (panga) on the menu. **A listing says the SM Lanang branch is permanently closed** (check which branch these two entries are). | C | Wanderlog, Airbnb |
| Jack's Ridge Resort and Restaurant (21) | Mid-range | No prices. "Generous portions and reasonable prices" (general description). Known for grilled tuna jaw. | C | Evendo, Edge Davao (2016) |
| Balik Bukid Farm and Kitchen (6) | Mid-range | No prices. Reviewers say "affordable", one says a small serving for the price. **One listing shows it temporarily closed.** | C | Wanderlog, Inquirer |
| Rekado Filipino Cuisine (34) | Mid-range | No prices found. Dishes mostly for sharing (kalderobo, bulalo, seafood platter). | none | SunStar, Airbnb |
| The Swiss Deli Restaurant (37) | Mid-range | No prices. **An Airbnb listing marks the J.P. Laurel branch permanently closed**; a Matina branch is listed separately. | none | Airbnb, Trip.com |
| New Davao Famous Restaurant Corp (28) | blank | No prices. Siopao, lumpia, lomi; "generous servings". Also on Foodpanda (delivery menu has prices). | none | SunStar, Wanderlog |
| Garden Oases Restaurant (15) | Mid-range | No prices; the closest match may be "Oases Seafood Restaurant" (not confirmed to be the same place). | none | SunStar |
| Garden By The Bay Corporation (14) | Mid-range | No prices; the closest match is "Garden Bay Restaurant & Resort", Maryknoll Drive, Lanang (not confirmed to be the same). | none | Kupi |
| Kublai Khan Mongolian Restaurant (2) | Mid-range | Nothing for Davao (results were Adelaide, Singapore, Perth: not used). | none | |
| Hillside 88 Bar and Restaurant (17) | Mid-range | Nothing found. | none | |
| Everything else (about 30 listings) | mixed | Not searched. | none | |

## What this means for the tiers

- Vikings at about P890 to P990 a head is the clearest case for the top tier. It is blank in the database today.
- Tsuru, Nonki and Blue Post sit at "mid-range to upper mid-range" on the evidence: dishes of P300 to P700, and per-person
  spends of P300 to P1,300 depending on what is ordered. The current Mid-range is defensible; nothing here contradicts it.
- The app does not define peso bands for Budget-Friendly / Mid-range / Premium, so any new tier needs a stated rule first.

## Possible closures to check (the database has an operating_status field)

- Swiss Deli (J.P. Laurel branch), Marina Tuna (SM Lanang branch), Balik Bukid (temporarily). These come from third-party
  listings that may be out of date or about a different branch. Confirm by phone or Facebook before marking anything closed.
