# ExploreDVO — Synthetic Sample Catalogue: Complete 130-Record Specification (REVIEW ONLY)

> **Status: PROPOSAL FOR REVIEW. No records have been inserted, updated, or deleted. No importer has been executed.**
> Every record in this file is **synthetic testing data**: fictional venue, synthetic coordinates near a real
> municipality, invented amenities/prices/hours for parser and algorithm testing. Nothing here asserts that a
> real establishment exists, nor that any record is DOT-accredited. On import, every record will carry the
> ` [SAMPLE]` name suffix and exactly one placeholder accreditation record (`SAMPLE-####`, `is_placeholder=true`),
> so `App\Support\SampleData` classifies it as **Sample Data, not DOT-verified** on every surface.

## 1. Testing nodes: principal and secondary, by system region

Principal node = the cluster anchor (real city/provincial centre). Secondary node = a second municipality area
in the same system region so cross-municipality travel, stay transfers, and meal fallbacks are testable.
Municipality below is the **intended assignment** declared in the record's `location` text
(`<landmark/barangay>, <Municipality/City>, <Province>`) — the application has no municipality column.

| System region | Principal node (municipalities) | Secondary node (municipalities) | Records anchored there |
|---|---|---|---|
| Davao City | Davao City — Poblacion/Talomo/Maa districts | Davao City — Toril/Catalunan/Marilog upland districts (same city, distinct geography) | Principal: D-01..D-04, A-01..A-03, R-01..R-03, V-01..V-03. Secondary: D-05, D-06, V-04 |
| Island Garden City of Samal | Samal City — Babak district | Samal City — Peñaplata, Kaputian districts + Talikud Island | Principal: D-09, D-12, A-04, R-04, V-05. Secondary: D-07, D-08, D-10, D-11, D-13, D-14, A-05..A-07, R-05..R-07, V-06, V-07 |
| Davao del Norte | Tagum City | Panabo City + Carmen; upland: Kapalong, Santo Tomas, New Corella | Principal: D-18, A-08, R-08, V-08. Secondary: D-15..D-17, D-19..D-22, A-09..A-11, R-09..R-11, V-09, V-10 |
| Davao de Oro | Nabunturan | Maragusan (highland); Maco, New Bataan, Compostela, Monkayo | Principal: D-23, D-27, A-12, R-12, V-11. Secondary: D-24..D-26, D-28..D-30, A-13..A-15, R-13..R-15, V-12, V-13 |
| Davao Occidental | Malita | Santa Maria (coastal); Jose Abad Santos, Don Marcelino, Sarangani (remote coastal) | Principal: D-33, D-34, D-37, A-16, A-18, R-16, R-18, V-14. Secondary: D-31, D-32, D-35, D-36, D-38, A-17, A-19, R-17, R-19, V-15, V-16 |
| Davao Oriental | Mati City | Baganga + Cateel (northern coast); Governor Generoso (southern coast) | Principal: D-39..D-42, D-44, A-20, A-21, R-20, R-21, V-17, V-18. Secondary: D-43, D-45, D-46, A-22, A-23, R-22, R-23, V-19 |
| Davao del Sur | Digos City incl. Kapatagan (highland barangay of Digos) | Santa Cruz + Bansalan; Matanao, Sulop | Principal: D-47, D-48, D-50, A-24, A-25, R-24, R-25, V-20, V-21. Secondary: D-49, D-51..D-54, A-26, A-27, R-26, R-27, V-22 |

**Davao del Norte detail (requested):** the system region `Davao del Norte` covers the mainland province only;
Samal City is the separate Samal system region (ferry logic). Mainland municipalities represented in this
catalogue: Tagum City (principal), Panabo City, Carmen, Kapalong, Santo Tomas, New Corella (secondary/upland).
Municipalities intentionally not sampled (no node): Asuncion, Braulio E. Dujali, Talaingod, San Isidro —
they remain gaps for future verified DOT data, not synthetic fill.

## 2. Category totals (arithmetic verified)

| System region | Destinations | Accommodations | Restaurants | Souvenir centres | Total |
|---|---|---|---|---|---|
| Davao City | 6 | 3 | 3 | 4 | 16 |
| Island Garden City of Samal | 8 | 4 | 4 | 3 | 19 |
| Davao del Norte | 8 | 4 | 4 | 3 | 19 |
| Davao de Oro | 8 | 4 | 4 | 3 | 19 |
| Davao Occidental | 8 | 4 | 4 | 3 | 19 |
| Davao Oriental | 8 | 4 | 4 | 3 | 19 |
| Davao del Sur | 8 | 4 | 4 | 3 | 19 |
| **Total** | **54** | **27** | **27** | **22** | **130** |

Conventions: amenity codes are keys in `App\Support\Amenities::CATALOG`. Destinations store them as exact
labels in `destination_tags (kind='amenity')`; other kinds store keys in `listing_amenities`.
Every record has >= 4 kind-legal amenities. **Repeated amenity combinations across records are acceptable
when realistic** (approved correction) — validity and category-appropriateness are required, uniqueness is not.
All destinations: `rating=0, review_count=0, featured=false, operating_status=open, archived_at=null`.
Descriptions are one-line summaries here; the import file will carry full test-data descriptions
(activities, access/transport, facilities) in synthetic wording.

## 3. Destinations (54)

Columns: ID | Name (base, ` [SAMPLE]` appended on import) | Municipality (intended) | Coordinates |
Type | Category tags | Amenities | Hours | Duration | Price tier (entry fee PHP, synthetic)

### 3.1 Davao City (D-01..D-06)

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-01 | Sample Gulfview Public Beach | Davao City (Talomo) | 7.0450, 125.5950 | Beach & Leisure | Beach | parking restroom picnic ramp accwc firstaid | 6:00 AM-6:00 PM | 4 hours | Free (0-0) |
| D-02 | Sample Durian Food Hall & Market | Davao City (Poblacion) | 7.0700, 125.6150 | Cultural Heritage | Food Tasting, City Center | restroom restaurant ac wifi ramp accwc | 9:00 AM-9:00 PM | 90 minutes | Budget-Friendly (50-150) |
| D-03 | Sample Artisan Craft & Souvenir Centre | Davao City (Poblacion) | 7.0680, 125.6090 | Cultural Heritage | City Center, Shopping, Souvenir | restroom ac wifi ramp accwc info | 10:00 AM-8:00 PM | 90 minutes | Free (0-0) |
| D-04 | Sample Riverside Heritage Museum | Davao City (Maa) | 7.0980, 125.5820 | Cultural Heritage | Cultural Heritage, Museum | parking restroom ac info ramp accwc | 9:00 AM-5:00 PM; Closed Mon | 2 hours | Budget-Friendly (50-100) |
| D-05 | Sample Upland Botanical Park | Davao City (Catalunan) | 7.0350, 125.5400 | Nature & Leisure | Nature, Garden, Cool Climate | parking restroom restaurant picnic firstaid info | 7:00 AM-5:00 PM | 3 hours | Mid-range (100-300) |
| D-06 | Sample Hillcrest Wellness Garden (role: optional) | Davao City (Matina) | 7.0600, 125.5650 | Wellness & Spa | Relaxation, Spa, Garden | parking restroom pool ac wifi ramp | 10:00 AM-9:00 PM | 90 minutes | Premium (500-1500) |

### 3.2 Island Garden City of Samal (D-07..D-14) — all coordinates on Samal/Talikud islands; region = Samal (ferry applies only to strait crossings)

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-07 | Sample South Coast White Beach | Samal (Kaputian) | 6.9850, 125.7050 | Beach & Leisure | Beach, Island Hopping | parking restroom picnic firstaid lockers info | 6:00 AM-6:00 PM | half day | Budget-Friendly (30-100) |
| D-08 | Sample Coral Garden Snorkel Park | Samal (Talikud) | 6.9450, 125.6950 | Beach & Leisure | Beach, Turtle Sanctuary | restroom restaurant firstaid lockers info picnic | 7:00 AM-4:00 PM | 3 hours | Mid-range (150-400) |
| D-09 | Sample Island Mangrove Boardwalk | Samal (Babak) | 7.1250, 125.6650 | Nature & Leisure | Nature, Turtle Sanctuary | parking restroom ramp accwc info picnic | 7:00 AM-5:00 PM | 90 minutes | Budget-Friendly (20-50) |
| D-10 | Sample Island Pearl Farm Tour | Samal (Penaplata) | 7.0650, 125.7250 | Farm Tourism | Farm Tour, Food Tasting, Nature | parking restroom restaurant picnic info firstaid | 8:00 AM-5:00 PM | 2 hours | Mid-range (200-500) |
| D-11 | Sample Island Ridge Viewpoint Trail | Samal (Talikud) | 6.9550, 125.7100 | Adventure & Hiking | Hiking | parking restroom info firstaid picnic lockers | 6:00 AM-4:00 PM | 3 hours | Budget-Friendly (20-50) |
| D-12 | Sample North Coast Heritage Park | Samal (Babak) | 7.1180, 125.6600 | Cultural Heritage | Cultural Heritage | parking restroom info ramp accwc picnic | 8:00 AM-5:00 PM | 90 minutes | Budget-Friendly (20-50) |
| D-13 | Sample East Reef Marine Learning Park | Samal (Penaplata) | 7.0550, 125.7350 | Wildlife | Wildlife, Conservation | parking restroom info firstaid lockers restaurant | 7:00 AM-4:00 PM | 2 hours | Mid-range (100-250) |
| D-14 | Sample Island Highland Garden | Samal (upland) | 7.0400, 125.7000 | Nature & Leisure | Nature, Garden, Cool Climate | parking restroom restaurant picnic ramp info | 7:00 AM-5:00 PM | 2 hours | Budget-Friendly (50-120) |

### 3.3 Davao del Norte (D-15..D-22) — mainland province only; Samal City records are in 3.2

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-15 | Sample Northern Coastal Beach Park | Carmen | 7.3600, 125.7200 | Beach & Leisure | Beach | parking restroom picnic restaurant firstaid lockers | 6:00 AM-6:00 PM | 4 hours | Budget-Friendly (30-80) |
| D-16 | Sample Banana & Cacao Farm Experience | Panabo City | 7.3000, 125.6800 | Farm Tourism | Farm Tour, Food Tasting, Nature | parking restroom restaurant ramp picnic info | 8:00 AM-5:00 PM | 2 hours | Mid-range (150-350) |
| D-17 | Sample Wetland Bird Sanctuary | Carmen | 7.3550, 125.7000 | Wildlife | Wildlife, Conservation | parking restroom info firstaid picnic ramp | 7:00 AM-5:00 PM | 2 hours | Budget-Friendly (50-100) |
| D-18 | Sample City Heritage Plaza & Museum | Tagum City | 7.4500, 125.8120 | Cultural Heritage | Cultural Heritage, Museum, City Center | parking restroom ac info ramp accwc | 9:00 AM-6:00 PM | 90 minutes | Free (0-0) |
| D-19 | Sample Southern River Nature Trail | Panabo City | 7.2900, 125.6950 | Nature & Adventure | Nature | parking restroom firstaid picnic info lockers | 7:00 AM-4:00 PM | 2 hours | Budget-Friendly (20-50) |
| D-20 | Sample Upland Nature Park | Kapalong | 7.5800, 125.5050 | Nature & Adventure | Nature, Hiking | parking restroom picnic firstaid info ramp | 7:00 AM-4:00 PM | 3 hours | Budget-Friendly (30-80) |
| D-21 | Sample Inland Agri Park | Santo Tomas | 7.5380, 125.6220 | Farm Tourism | Farm Tour, Nature | parking restroom restaurant picnic ramp info | 8:00 AM-5:00 PM | 2 hours | Budget-Friendly (50-120) |
| D-22 | Sample Highland Hot Spring Garden | New Corella | 7.5850, 125.8280 | Nature & Leisure | Nature, Relaxation | parking restroom pool picnic firstaid info | 6:00 AM-7:00 PM | 3 hours | Budget-Friendly (50-150) |

### 3.4 Davao de Oro (D-23..D-30)

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-23 | Sample Valley Hot Spring Pools | Nabunturan | 7.5300, 125.9950 | Nature & Leisure | Nature, Relaxation | parking restroom restaurant pool firstaid picnic | 6:00 AM-8:00 PM | 3 hours | Budget-Friendly (50-150) |
| D-24 | Sample Highland Wellness & Tea Garden (role: optional) | Maragusan | 7.3500, 126.1800 | Wellness & Spa | Relaxation, Spa, Cool Climate | parking restroom ac wifi ramp picnic | 9:00 AM-8:00 PM | 2 hours | Mid-range (300-800) |
| D-25 | Sample Highland Tiered Falls & Trail | Maragusan | 7.3350, 126.1550 | Nature & Adventure | Nature, Hiking | parking restroom picnic firstaid info lockers | 7:00 AM-4:00 PM | 3 hours | Budget-Friendly (30-80) |
| D-26 | Sample Crater Lake Viewpoint Trek | Maco | 7.3900, 126.0550 | Adventure & Hiking | Hiking | parking restroom info firstaid picnic lockers | 6:00 AM-5:00 PM | half day | Mid-range (100-250) |
| D-27 | Sample Valley Craft & Pasalubong Market | Nabunturan | 7.6000, 125.9650 | Cultural Heritage | City Center, Shopping, Souvenir | parking restroom ac wifi ramp accwc | 9:00 AM-7:00 PM | 60 minutes | Free (0-0) |
| D-28 | Sample Eastern River Falls Park | New Bataan | 7.5480, 126.1350 | Nature & Adventure | Nature | parking restroom picnic firstaid info lockers | 7:00 AM-4:00 PM | 3 hours | Budget-Friendly (40-100) |
| D-29 | Sample Highland View Park | Compostela | 7.6680, 126.0520 | Nature & Leisure | Nature, Cool Climate | parking restroom picnic ramp info firstaid | 7:00 AM-5:00 PM | 2 hours | Budget-Friendly (30-80) |
| D-30 | Sample Northern Cultural & Craft Village | Monkayo | 7.7050, 126.0380 | Cultural Heritage | Cultural Heritage, Farm Tour | parking restroom restaurant info picnic ramp | 8:00 AM-5:00 PM | 2 hours | Budget-Friendly (50-120) |

### 3.5 Davao Occidental (D-31..D-38)

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-31 | Sample Western White Beach | Santa Maria | 6.5450, 125.4750 | Beach & Leisure | Beach | parking restroom picnic restaurant firstaid lockers | 6:00 AM-6:00 PM | 4 hours | Budget-Friendly (20-60) |
| D-32 | Sample Western Marine Sanctuary | Santa Maria | 6.5350, 125.4900 | Wildlife | Wildlife, Conservation, Turtle Sanctuary | parking restroom info firstaid lockers picnic | 7:00 AM-4:00 PM | 2 hours | Budget-Friendly (50-100) |
| D-33 | Sample Coastal Mangrove & River Park | Malita | 6.4100, 125.6000 | Nature & Leisure | Nature, Turtle Sanctuary | parking restroom ramp accwc picnic info | 7:00 AM-5:00 PM | 90 minutes | Free (0-0) |
| D-34 | Sample Upland Cultural Village Experience | Malita | 6.4300, 125.5900 | Cultural Heritage | Cultural Heritage, Food Tasting | parking restroom restaurant info picnic ramp | 8:00 AM-5:00 PM | 2 hours | Mid-range (150-300) |
| D-35 | Sample Southern Cove Viewpoint Trail | Jose Abad Santos | 5.9200, 125.6450 | Adventure & Hiking | Hiking, Nature | parking restroom info firstaid picnic lockers | 6:00 AM-5:00 PM | 3 hours | Budget-Friendly (20-50) |
| D-36 | Sample Central Coastal Park | Don Marcelino | 6.2680, 125.5350 | Beach & Leisure | Beach, Nature | parking restroom picnic firstaid info ramp | 6:00 AM-6:00 PM | 3 hours | Budget-Friendly (20-60) |
| D-37 | Sample Town Heritage & Weaving House | Malita | 6.4080, 125.6150 | Cultural Heritage | Cultural Heritage | parking restroom info ramp ac accwc | 9:00 AM-5:00 PM; Closed Tue | 90 minutes | Budget-Friendly (30-80) |
| D-38 | Sample Southern Coastal Viewpoint | Sarangani | 5.9850, 125.3350 | Nature & Leisure | Nature | parking restroom picnic info firstaid ramp | 7:00 AM-5:00 PM | 2 hours | Free (0-0) |

> **Name correction (requested item 2):** D-31 was previously "Sample Malalag Bay White Beach". Malalag Bay is a
> real bay associated with Malalag, Davao del Sur — not Santa Maria, Davao Occidental. Renamed to the neutral
> fictional **Sample Western White Beach**. Its municipality assignment (Santa Maria) and coordinates are unchanged.
> The same neutralisation was applied to: D-23 (was "Mainit Hot Spring"), D-25 (was "Candalaga Falls"),
> D-40 (was "Pujada Bay Island Day Park"), D-44 (was "Sleeping Ridge Walk"), and all venue names embedding real
> district/bay names (Babak, Kaputian, Talikud, Dahican, Maa, etc. — see Sections 4-6). Real place names now appear
> **only** in the Municipality/location fields, never as the fictional venue's own name.

### 3.6 Davao Oriental (D-39..D-46)

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-39 | Sample Sunrise Surf Beach | Mati City | 6.9600, 126.2700 | Beach & Surfing | Beach, Surfing | parking restroom restaurant firstaid lockers picnic | 6:00 AM-6:00 PM | half day | Budget-Friendly (30-80) |
| D-40 | Sample Bay Island Day Park | Mati City | 6.8800, 126.2800 | Beach & Leisure | Beach, Island Hopping | parking restroom restaurant picnic firstaid lockers | 7:00 AM-5:00 PM | 4 hours | Mid-range (100-300) |
| D-41 | Sample Turtle & Mangrove Nursery | Mati City | 6.9150, 126.2600 | Wildlife | Wildlife, Conservation, Turtle Sanctuary | parking restroom info ramp accwc picnic | 8:00 AM-4:00 PM; Closed Sun | 90 minutes | Budget-Friendly (20-60) |
| D-42 | Sample Provincial Museum Annex | Mati City | 6.9450, 126.2450 | Cultural Heritage | Cultural Heritage, Museum, City Center | parking restroom ac info ramp accwc | 9:00 AM-6:00 PM | 90 minutes | Budget-Friendly (30-80) |
| D-43 | Sample Northern Tiered Falls Park | Cateel | 7.7400, 126.2950 | Nature & Adventure | Nature | parking restroom picnic firstaid info lockers | 8:00 AM-4:00 PM | 3 hours | Budget-Friendly (50-150) |
| D-44 | Sample Eastern Ridge Walk | Mati City | 6.8900, 126.1800 | Adventure & Hiking | Hiking | parking restroom info firstaid picnic lockers | 6:00 AM-5:00 PM | 2 hours | Free (0-30) |
| D-45 | Sample Northern Heritage & River Park | Baganga | 7.4750, 126.5580 | Cultural Heritage | Cultural Heritage, Nature | parking restroom info picnic ramp accwc | 8:00 AM-5:00 PM | 2 hours | Budget-Friendly (30-80) |
| D-46 | Sample Southern Headland Viewpoint | Governor Generoso | 6.6520, 126.1580 | Nature & Leisure | Nature, Beach | parking restroom picnic info firstaid ramp | 7:00 AM-5:00 PM | 2 hours | Budget-Friendly (20-50) |

### 3.7 Davao del Sur (D-47..D-54)

| ID | Name | Municipality | Lat, Lng | Type | Tags | Amenities | Hours | Duration | Tier (fee) |
|---|---|---|---|---|---|---|---|---|---|
| D-47 | Sample Highland Flower Park | Digos City (Kapatagan) | 6.9450, 125.2780 | Nature & Leisure | Nature, Garden, Cool Climate | parking restroom restaurant picnic ramp info | 7:00 AM-6:00 PM | 2 hours | Mid-range (100-200) |
| D-48 | Sample Foothills Forest Trail | Digos City (Kapatagan) | 6.9350, 125.2650 | Adventure & Hiking | Hiking | parking restroom firstaid info picnic lockers | 6:00 AM-4:00 PM | full day | Mid-range (150-400) |
| D-49 | Sample Weaving & Culture House | Santa Cruz | 6.8520, 125.4080 | Cultural Heritage | Cultural Heritage, Shopping, Souvenir | parking restroom restaurant info ramp accwc | 8:00 AM-5:00 PM | 2 hours | Budget-Friendly (100-250) |
| D-50 | Sample Coastal Fish Market & Food Row | Digos City | 6.7400, 125.3600 | Cultural Heritage | Food Tasting, City Center | parking restroom restaurant ramp accwc wifi | 8:00 AM-8:00 PM | 90 minutes | Budget-Friendly (50-150) |
| D-51 | Sample Coastal Mangrove Boardwalk | Santa Cruz | 6.8450, 125.4150 | Nature & Leisure | Nature, Turtle Sanctuary | parking restroom ramp accwc info picnic | 7:00 AM-5:00 PM | 90 minutes | Budget-Friendly (20-50) |
| D-52 | Sample Inland Farm & Food Park | Bansalan | 6.7950, 125.3480 | Farm Tourism | Farm Tour, Food Tasting | parking restroom restaurant picnic ramp info | 8:00 AM-5:00 PM | 2 hours | Mid-range (100-250) |
| D-53 | Sample Cave & River Park | Matanao | 6.7050, 125.2650 | Nature & Adventure | Nature | parking restroom picnic firstaid info lockers | 7:00 AM-4:00 PM | 3 hours | Budget-Friendly (40-100) |
| D-54 | Sample Southern Coastal Eco Park | Sulop | 6.5950, 125.3450 | Beach & Leisure | Beach, Nature | parking restroom picnic firstaid ramp info | 6:00 AM-6:00 PM | 3 hours | Budget-Friendly (20-60) |

## 4. Accommodations (27)

All: check-in/check-out included (Hotel/Resort 14:00/12:00; Homestay 13:00/11:00; Inn 14:00/11:30).
Room-type price bands (PHP, synthetic) bracket the nightly rate. `dot_classification` is left null for all.

| ID | Name | Municipality | Lat, Lng | Type (resort settings) | Amenities | Nightly PHP | Room types (bands) | Check-in/out |
|---|---|---|---|---|---|---|---|---|
| A-01 | Sample Central City Hotel | Davao City | 7.0720, 125.6110 | Hotel | wifi ac elevator restaurant accwc parking | 1800 | Standard Room 1500-1900; Deluxe Room 2000-2600 | 14:00 / 12:00 |
| A-02 | Sample Coastal Road Resort | Davao City | 7.0500, 125.6000 | Resort (Beach) | wifi pool ac parking restaurant ramp | 3500 | Garden Room 3000-3800; Pool Villa 5500-7500 | 14:00 / 12:00 |
| A-03 | Sample Riverside Homestay | Davao City | 7.0950, 125.5850 | Homestay | wifi parking ramp accwc ac | 800 | Fan Room 600-800; Family AC Room 1000-1400 | 13:00 / 11:00 |
| A-04 | Sample North Bay Beach Resort | Samal (Babak) | 7.1200, 125.6700 | Beach Resort (Beach, Island) | wifi pool ac parking restaurant ramp | 2800 | Beachfront Room 2400-3200; Family Cottage 4000-5200 | 14:00 / 12:00 |
| A-05 | Sample East Island Resort | Samal (Penaplata) | 7.0600, 125.7300 | Resort (Island) | wifi pool ac restaurant ramp accwc | 4200 | Deluxe Room 3600-4600; Suite 6000-8000 | 14:00 / 12:00 |
| A-06 | Sample South Island Beach Inn | Samal (Kaputian) | 6.9900, 125.7000 | Hotel | wifi ac parking accwc ramp | 1200 | Standard Room 900-1200; Family Room 1600-2100 | 14:00 / 11:30 |
| A-07 | Sample West Island Homestay | Samal (Talikud) | 6.9500, 125.7050 | Homestay | wifi parking ramp restaurant ac | 700 | Island Room 500-700; Family Room 900-1300 | 13:00 / 11:00 |
| A-08 | Sample Central City Hotel North | Tagum City | 7.4480, 125.8100 | Hotel | wifi ac elevator parking restaurant accwc | 1600 | Standard Room 1300-1700; Deluxe Room 1900-2400 | 14:00 / 12:00 |
| A-09 | Sample Coastal Beach Resort North | Carmen | 7.3650, 125.7250 | Beach Resort (Beach) | wifi pool parking restaurant ramp ac | 2400 | Cottage 2000-2600; Family Room 3200-4200 | 14:00 / 12:00 |
| A-10 | Sample Farm Homestay North | Panabo City | 7.2950, 125.6850 | Homestay | wifi parking restaurant ramp ac | 700 | Farm Room 500-700; Family Room 900-1200 | 13:00 / 11:00 |
| A-11 | Sample Upland Town Hotel | Kapalong | 7.5820, 125.5080 | Hotel | wifi ac parking restaurant accwc ramp | 1300 | Standard Room 1000-1300; Family Room 1700-2200 | 14:00 / 12:00 |
| A-12 | Sample Valley Town Hotel | Nabunturan | 7.6010, 125.9670 | Hotel | wifi ac parking restaurant accwc ramp | 1400 | Standard Room 1100-1500; Deluxe Room 1700-2200 | 14:00 / 12:00 |
| A-13 | Sample Highland Mountain Resort | Maragusan | 7.3480, 126.1750 | Resort (Mountain) | wifi pool parking restaurant ramp ac | 2600 | Highland Room 2200-2800; Cabin 3800-5000 | 14:00 / 12:00 |
| A-14 | Sample Lakeside Homestay | Maco | 7.3880, 126.0520 | Homestay | wifi parking ramp accwc restaurant | 750 | Lake Room 550-750; Family Room 950-1300 | 13:00 / 11:00 |
| A-15 | Sample Eastern Valley Inn | New Bataan | 7.5500, 126.1320 | Hotel | wifi ac parking accwc ramp restaurant | 1100 | Standard Room 900-1100; Family Room 1500-1900 | 14:00 / 11:30 |
| A-16 | Sample Coastal Town Hotel West | Malita | 6.4130, 125.6120 | Hotel | wifi ac parking accwc ramp restaurant | 1300 | Standard Room 1000-1400; Family Room 1800-2300 | 14:00 / 12:00 |
| A-17 | Sample Western Bay Beach Resort | Santa Maria | 6.5480, 125.4780 | Beach Resort (Beach) | wifi pool parking restaurant ramp ac | 2200 | Beach Room 1800-2400; Cottage 3000-4000 | 14:00 / 12:00 |
| A-18 | Sample Upland Homestay West | Malita | 6.4280, 125.5920 | Homestay | wifi parking ramp restaurant ac | 650 | Homestay Room 450-650; Family Room 800-1100 | 13:00 / 11:00 |
| A-19 | Sample Southern Coastal Inn | Jose Abad Santos | 5.9220, 125.6420 | Hotel | wifi ac parking ramp accwc restaurant | 1000 | Standard Room 800-1000; Family Room 1400-1800 | 14:00 / 11:30 |
| A-20 | Sample Bay City Hotel East | Mati City | 6.9430, 126.2470 | Hotel | wifi ac elevator parking restaurant accwc | 1700 | Standard Room 1400-1800; Bay-view Deluxe 2200-2900 | 14:00 / 12:00 |
| A-21 | Sample Sunrise Surf Beach Resort | Mati City | 6.9580, 126.2680 | Beach Resort (Beach) | wifi pool parking restaurant ramp ac | 2500 | Surf Room 2000-2600; Family Cottage 3600-4800 | 14:00 / 12:00 |
| A-22 | Sample Northern Coastal Resort | Baganga | 7.5900, 126.5800 | Resort (Beach) | wifi pool ac parking restaurant ramp | 2900 | Coastal Room 2400-3100; Villa 4500-6000 | 14:00 / 12:00 |
| A-23 | Sample River Town Inn | Cateel | 7.7880, 126.4520 | Hotel | wifi ac parking ramp restaurant accwc | 1200 | Standard Room 900-1200; Family Room 1600-2100 | 14:00 / 11:30 |
| A-24 | Sample Southern City Hotel | Digos City | 6.7570, 125.3520 | Hotel | wifi ac parking restaurant accwc ramp | 1500 | Standard Room 1200-1600; Deluxe Room 1800-2300 | 14:00 / 12:00 |
| A-25 | Sample Highland Mountain Resort South | Digos City (Kapatagan) | 6.9460, 125.2800 | Resort (Mountain) | wifi pool parking restaurant ramp ac | 2700 | Mountain Room 2300-3000; Cabin 4200-5500 | 14:00 / 12:00 |
| A-26 | Sample Coastal Homestay South | Santa Cruz | 6.8480, 125.4120 | Homestay | wifi parking ramp accwc restaurant | 700 | Coastal Room 500-700; Family Room 900-1200 | 13:00 / 11:00 |
| A-27 | Sample Inland Farm Stay | Bansalan | 6.7920, 125.3500 | Resort (Mountain) | wifi parking restaurant ramp pool ac | 1500 | Farm Room 1200-1500; Family Room 2000-2600 | 14:00 / 12:00 |

## 5. Restaurants (27)

Cuisines use only the existing vocabulary. Specialties and dine-in/takeout are in the description (no such columns exist).
Split-shift rows marked (split) still cover the 11:30-13:00 lunch-start window.

| ID | Name | Municipality | Lat, Lng | Cuisine | Tier | Amenities | Hours | Specialties (synthetic, description) |
|---|---|---|---|---|---|---|---|---|
| R-01 | Sample Central Grill House | Davao City | 7.0710, 125.6130 | Filipino | Budget-Friendly | parking restroom ac wifi kids stepfree | 10:00 AM-9:00 PM | Grilled tuna belly; dine-in + takeout |
| R-02 | Sample Coastal Seafood Kitchen | Davao City | 7.0480, 125.5980 | Seafood | Mid-range | parking restroom outdoor wifi ac kids | 10:00 AM-9:00 PM | Kinilaw, grilled squid; outdoor deck |
| R-03 | Sample Garden Restaurant (split) | Davao City | 7.0960, 125.5840 | Filipino | Budget-Friendly | parking restroom ac stepfree accwc outdoor | 11:00 AM-2:00 PM; 5:00 PM-9:00 PM | Garden dining; lunch sets |
| R-04 | Sample North Port Eatery | Samal (Babak) | 7.1180, 125.6680 | Seafood | Budget-Friendly | parking restroom outdoor kids wifi ac | 9:00 AM-8:00 PM | Port-side seafood; takeout counter |
| R-05 | Sample East Beach Cafe | Samal (Penaplata) | 7.0620, 125.7280 | Cafe | Budget-Friendly | parking restroom outdoor wifi ac stepfree | 8:00 AM-8:00 PM | Coffee, all-day breakfast |
| R-06 | Sample South Island Grill | Samal (Kaputian) | 6.9880, 125.7020 | Filipino | Budget-Friendly | parking restroom outdoor kids ac wifi | 10:00 AM-8:00 PM | Beach grill, group platters |
| R-07 | Sample West Island Kitchen | Samal (Talikud) | 6.9520, 125.7080 | Filipino Seafood | Budget-Friendly | parking restroom outdoor wifi kids ac | 9:00 AM-7:00 PM | Island seafood, family sets |
| R-08 | Sample City Food Plaza | Tagum City | 7.4490, 125.8080 | Filipino & International | Mid-range | parking restroom ac wifi stepfree accwc | 10:00 AM-9:00 PM | Food-hall stalls; dine-in |
| R-09 | Sample Coastal Seafood House North | Carmen | 7.3620, 125.7220 | Filipino Seafood | Mid-range | parking restroom outdoor kids ac wifi | 10:00 AM-8:00 PM | Crab and shrimp by weight |
| R-10 | Sample Farm Kitchen North | Panabo City | 7.2980, 125.6830 | Farm-to-Table | Mid-range | parking restroom outdoor wifi stepfree accwc | 9:00 AM-8:00 PM | Banana/cacao farm dishes |
| R-11 | Sample Upland Town Cafe | Kapalong | 7.5830, 125.5060 | Cafe | Budget-Friendly | parking restroom wifi outdoor ac stepfree | 8:00 AM-7:00 PM | Coffee, native pastries |
| R-12 | Sample Valley Home Kitchen (split) | Nabunturan | 7.6010, 125.9660 | Filipino | Budget-Friendly | parking restroom ac wifi stepfree accwc | 11:00 AM-2:00 PM; 5:00 PM-9:00 PM | Home-style lunch sets |
| R-13 | Sample Highland Cafe East | Maragusan | 7.3490, 126.1780 | Cafe | Budget-Friendly | parking restroom wifi outdoor ac stepfree | 8:00 AM-7:00 PM | Highland coffee, pastries |
| R-14 | Sample Lakeview Restaurant | Maco | 7.3890, 126.0540 | Filipino | Mid-range | parking restroom outdoor ac wifi kids | 9:00 AM-8:00 PM | Lakeview terrace dining |
| R-15 | Sample Eastern River Cafe | New Bataan | 7.5510, 126.1330 | Cafe & Filipino | Budget-Friendly | parking restroom outdoor wifi stepfree ac | 9:00 AM-8:00 PM | River-side cafe meals |
| R-16 | Sample Bay Seafood House West | Malita | 6.4120, 125.6090 | Seafood | Budget-Friendly | parking restroom ac wifi kids stepfree | 10:00 AM-8:00 PM | Bay-side seafood paluto |
| R-17 | Sample Western Beach Grill | Santa Maria | 6.5470, 125.4770 | Bar & Grill | Mid-range | parking restroom outdoor wifi ac kids | 10:00 AM-8:00 PM | Beachfront grill |
| R-18 | Sample Upland Kitchen West | Malita | 6.4260, 125.5940 | Filipino | Budget-Friendly | parking restroom wifi stepfree accwc ac | 9:00 AM-8:00 PM | Upland vegetable dishes |
| R-19 | Sample Southern Town Eatery | Jose Abad Santos | 5.9210, 125.6440 | Filipino | Budget-Friendly | parking restroom outdoor wifi kids stepfree | 9:00 AM-8:00 PM | Town eatery, takeout |
| R-20 | Sample Bay Grill East | Mati City | 6.9440, 126.2460 | Filipino | Mid-range | parking restroom ac wifi kids stepfree | 10:00 AM-9:00 PM | Grilled tuna panga |
| R-21 | Sample Sunrise Surfer Cafe (split) | Mati City | 6.9590, 126.2690 | Cafe & Filipino | Budget-Friendly | parking restroom outdoor wifi ac stepfree | 11:00 AM-2:00 PM; 5:00 PM-9:00 PM | Surfer breakfast, lunch sets |
| R-22 | Sample Northern Coastal Kitchen | Baganga | 7.5920, 126.5820 | Seafood | Mid-range | parking restroom outdoor kids ac wifi | 10:00 AM-8:00 PM | Coastal seafood |
| R-23 | Sample River Town Restaurant | Cateel | 7.7900, 126.4500 | Filipino | Budget-Friendly | parking restroom ac wifi stepfree accwc | 9:00 AM-8:00 PM | River-town Filipino meals |
| R-24 | Sample Southern Grill Row | Digos City | 6.7550, 125.3540 | Filipino | Budget-Friendly | parking restroom ac wifi kids stepfree | 10:00 AM-9:00 PM | Lechon and grill row |
| R-25 | Sample Highland Restaurant South | Digos City (Kapatagan) | 6.9470, 125.2790 | Filipino | Mid-range | parking restroom ac outdoor wifi kids | 8:00 AM-8:00 PM | Highland vegetables, coffee |
| R-26 | Sample Seaside Eatery South | Santa Cruz | 6.8500, 125.4180 | Seafood | Budget-Friendly | parking restroom outdoor kids wifi ac | 10:00 AM-8:00 PM | Seaside eatery, takeout |
| R-27 | Sample Farm Restaurant South | Bansalan | 6.7930, 125.3520 | Farm-to-Table | Mid-range | parking restroom outdoor wifi stepfree ac | 9:00 AM-8:00 PM | Farm-to-table sets |

## 6. Souvenir centres (22)

Products/specialties are in the description (the only supported place). **Opening hours and price range are
description text only** — `souvenir_centers` has no such columns; nothing here claims otherwise.
Hours shown below are the synthetic description values (all 9:00 AM-7:00 PM unless noted; V-01/V-08/V-20 9:00 AM-8:00 PM).

| ID | Name | Municipality | Lat, Lng | Amenities | Products (synthetic, description) | Price note (description) |
|---|---|---|---|---|---|---|
| V-01 | Sample Central Pasalubong Centre | Davao City | 7.0690, 125.6100 | restroom ac gcash stepfree accwc parking | Durian candies, dried mango, coffee | Budget-Friendly range |
| V-02 | Sample Coastal Craft Shop | Davao City | 7.0520, 125.6010 | parking restroom gcash ac stepfree accwc | Shell craft, woven bags | Budget-Friendly range |
| V-03 | Sample Riverside Craft & Garden Shop | Davao City | 7.0940, 125.5860 | parking restroom gcash ac accwc stepfree | Garden crafts, bottled preserves | Budget-Friendly range |
| V-04 | Sample Southern Pasalubong Row | Davao City (Toril) | 7.0180, 125.4980 | parking restroom gcash stepfree ac accwc | Fruit products, crafts | Budget-Friendly range |
| V-05 | Sample Ferry Port Craft Row | Samal (Babak) | 7.1190, 125.6690 | parking restroom gcash stepfree ac accwc | Ferry-port pasalubong, pearl accessories | Budget-Friendly range |
| V-06 | Sample Island Pearl & Craft Shop | Samal (Penaplata) | 7.0630, 125.7290 | parking restroom ac gcash accwc stepfree | Pearl craft, island souvenirs | Mid-range |
| V-07 | Sample South Beach Craft Stall | Samal (Kaputian) | 6.9890, 125.7030 | parking restroom gcash stepfree ac accwc | Beach craft, woven hats | Budget-Friendly range |
| V-08 | Sample City Pasalubong Hub North | Tagum City | 7.4485, 125.8090 | parking restroom ac gcash stepfree accwc | Banana chips, cacao products | Budget-Friendly range |
| V-09 | Sample Banana Craft Shop | Panabo City | 7.2970, 125.6840 | parking restroom gcash stepfree ac accwc | Banana-fibre products | Budget-Friendly range |
| V-10 | Sample Coastal Craft Shop North | Carmen | 7.3610, 125.7230 | parking restroom gcash ac stepfree accwc | Coastal crafts, dried goods | Budget-Friendly range |
| V-11 | Sample Valley Craft Market | Nabunturan | 7.6005, 125.9665 | parking restroom gcash ac stepfree accwc | Valley crafts, coffee | Budget-Friendly range |
| V-12 | Sample Highland Weave Shop | Maragusan | 7.3495, 126.1770 | parking restroom gcash stepfree ac accwc | Highland weaves (generic craft wording) | Mid-range |
| V-13 | Sample Eastern Pasalubong Stall | New Bataan | 7.5505, 126.1325 | parking restroom gcash ac stepfree accwc | Coffee, native pastries | Budget-Friendly range |
| V-14 | Sample Town Craft & Weave Centre | Malita | 6.4130, 125.6115 | parking restroom ac gcash accwc stepfree | Woven crafts (no authenticity claims) | Budget-Friendly range |
| V-15 | Sample Western Shell Craft Shop | Santa Maria | 6.5465, 125.4765 | parking restroom gcash stepfree ac accwc | Shell craft | Budget-Friendly range |
| V-16 | Sample Southern Craft Stall | Jose Abad Santos | 5.9215, 125.6435 | parking restroom gcash ac stepfree accwc | Coastal crafts | Budget-Friendly range |
| V-17 | Sample City Pasalubong Centre East | Mati City | 6.9435, 126.2475 | parking restroom ac gcash stepfree accwc | Coffee, dried goods | Budget-Friendly range |
| V-18 | Sample Sunrise Surf & Craft Shop | Mati City | 6.9585, 126.2685 | parking restroom gcash ac stepfree accwc | Surf apparel, local craft | Mid-range |
| V-19 | Sample Northern Weave & Craft Shop | Baganga | 7.5910, 126.5810 | parking restroom gcash stepfree ac accwc | Weaves, wood craft | Budget-Friendly range |
| V-20 | Sample City Pasalubong Row South | Digos City | 6.7560, 125.3535 | parking restroom ac gcash stepfree accwc | Mango/durian products | Budget-Friendly range |
| V-21 | Sample Highland Coffee & Craft Shop | Digos City (Kapatagan) | 6.9465, 125.2795 | parking restroom gcash ac stepfree accwc | Highland coffee beans, crafts | Mid-range |
| V-22 | Sample Coastal Craft Shop South | Santa Cruz | 6.8530, 125.4140 | parking restroom gcash stepfree ac accwc | Coastal crafts, preserves | Budget-Friendly range |

## 7. Geographical testing origins beyond Davao City and provincial centres

Origins are real municipal centres (origin coordinates only — no record is created for an origin).
Each scenario preserves existing travel rules (range radii by mode, 08:00/18:00, meal windows, drive budgets).

| ID | Origin (environment) | Coordinates | Scenario | What it validates |
|---|---|---|---|---|
| O-01 | Baganga, Davao Oriental (remote coastal) | 7.47, 126.56 | 3-day Beach+Nature far trip, car | Secondary-node viability; Baganga/Cateel cluster fills locally instead of recentring on Mati/Davao |
| O-02 | Santa Maria, Davao Occidental (coastal) | 6.54, 125.47 | 2-day Beach+Wildlife moderate trip | First local Davao Occidental itineraries; stay at A-17, meals at R-17 |
| O-03 | Jose Abad Santos (remote southern coastal) | 5.92, 125.65 | 2-day Hiking+Nature far trip | Sparse-node honesty: reserve filling, empty-day handling, 20:00 home rule |
| O-04 | Santo Tomas, Davao del Norte (inland agricultural) | 7.54, 125.62 | 2-day Farm+Food near trip, jeep | Agricultural origin; short-range farm coverage (D-21, A-10/R-10 day trips) |
| O-05 | Panabo City (agricultural/industrial) | 7.30, 125.68 | 2-day Food+Nature moderate, taxi | Cross-municipality Tagum<->Panabo<->Carmen within one province |
| O-06 | Maragusan, Davao de Oro (mountainous/highland) | 7.35, 126.18 | 2-day Hiking+Wellness far trip | Highland origin; full-day/half-day durations vs 18:00 cutoff; stay A-13 |
| O-07 | Kapatagan, Digos (highland) | 6.94, 125.27 | 2-day Hiking+Nature moderate trip | Highland day structure without duplicating Mt Apo; A-25/R-25/V-21 node |
| O-08 | Marilog, Davao City (mountainous upland district) | 7.42, 125.30 | 2-day Nature+Adventure moderate trip | Upland origin inside Davao City HUC; cool-climate records D-05/D-22 reachable |
| O-09 | Talikud Island, Samal (island) | 6.95, 125.70 | 2-day Beach island-only trip, car | Island self-containment: zero ferry legs intra-island; island stays/meals only |
| O-10 | Kaputian, Samal (island coastal) | 6.99, 125.70 | Samal -> Davao City 3-day far trip | Ferry charged exactly on strait crossings; stay change island->city evaluated |
| O-11 | Monkayo, Davao de Oro (inland) | 7.71, 126.04 | 3-day Culture+Nature far trip | Inland origin; Nabunturan/Monkayo inter-municipality legs |
| O-12 | Malalag, Davao del Sur (coastal) | 6.61, 125.40 | 2-day Beach+Food moderate trip | Coastal Davao del Sur origin (note: origin town only; no venue is named for its bay) |
| O-13 | Governor Generoso (southern coastal) | 6.65, 126.16 | 2-day Nature+Beach far trip | Southern Oriental secondary node; Mati as fallback, not default |
| O-14 | Carmen, Davao del Norte (coastal agricultural) | 7.36, 125.72 | 1-day Beach+Farm near trip | Same-municipality near trip; meal rule inside cluster |

## 8. What this synthetic dataset can and cannot validate

**Can validate (system behaviour):** recommendation ranking and interest coverage per cluster; range gating
(near/commutable/remote by mode); nearest-neighbour sequencing; theme-day grouping (after embeddings rebuild);
stay eligibility, continuity vs justified transfer, per-night stays; Apriori *mechanics* (fallback paths, since
synthetic venues start with zero transactions — correctly); opening-hours and duration handling; meal and
souvenir proximity rules; ferry logic; budget-tier discrimination (synthetic tiers/fees); accessibility matching
(records with ramp/accwc exist per cluster); provenance labelling end-to-end ( SampleData === sample on 130/130 ).

**Cannot validate (requires real, verified DOT data):** actual regional tourism supply or demand; real visitor
co-visitation (Apriori evidence about real tourists); rating/popularity differentiation (all synthetic records
have rating 0 by design — RS/PS stay neutral); real prices, hours, or capacities; real travel times on real roads
(coordinates are synthetic points, distances are Haversine-consistent test geometry, not surveyed routes);
establishment quality. **Synthetic records must never be reported as regional tourism supply, accredited
establishments, or verified listings in any report, paper table, or DOT submission.** Counts quoted externally
must always split official vs sample (see G5 scope).

## 9. Implementation readiness checklist (no action taken)

- [ ] Importer extension: `sample:import-destinations` writes `destination_tags` (category + amenity labels).
- [ ] New sample importer for accommodations (+ `accommodation_room_types`), restaurants, souvenir centres:
      required coordinates, Region XI bounds, slug + cross-kind name dedupe, `Amenities::allowed()` key check,
      >=4 amenities, dry-run / all-or-nothing / ` [SAMPLE]` suffix / `SAMPLE-####` placeholder / `--remove`.
- [ ] Tables (data only, no migrations): destinations, destination_tags, accommodations,
      accommodation_room_types, restaurants, souvenir_centers, listing_amenities, accreditation_records,
      destination_embeddings. `regions` read-only (7 existing labels, Samal separate).
- [ ] Validators: amenity kind-legality; tag vocabulary (verified list only); hours parse (`OpeningHours`);
      duration parse (`PreferenceFit::hours`); fee/room-band consistency; Haversine node-distance report
      (reject >20 km from declared node without secondary-node flag); Samal region<->island-coordinate cross-check.
- [ ] Visibility precondition: G3 (chatbot wording) fixed before public exposure; G1/G2/G4/G5 same release.
- [ ] Embeddings rebuild (`embeddings:build` on Ollama machine -> JSON -> server `embeddings:import`);
      validation shows theme-day/swap coverage, and the pre-rebuild degradation explicitly.
- [ ] Test scenarios V-01..V-11 + origins O-01..O-14; full suite via direct `vendor/bin/phpunit`.
- [ ] Deployment: manual post-deploy artisan steps only (dry-run -> review -> `--apply` per kind -> embeddings);
      never wired into `server-update.sh`. Rollback: triple-guarded `--remove` per kind + official-count check.

**END OF PROPOSAL — awaiting explicit approval. No database changes have been made.**
