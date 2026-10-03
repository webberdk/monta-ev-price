<?php
// Satser kontrolleret 3. oktober 2026. Kr/kWh, ekskl. moms bortset fra Andels tillæg.
// Radius kundekategori C. Kontrollér netselskab og personligt tillæg på Andel-regningen.
const PRISOMRAADE = 'DK2';
const STED = 'Greve';
const MOMS = 1.25;
const ANDEL_TILLAEG = 0.1463; // inkl. moms, https://andelenergi.dk/el/flexenergi/
const ENERGINET_NET = 0.043;
const ENERGINET_SYSTEM = 0.072; // https://energinet.dk/media/5v3pikp3/energinets_tarifkatalog_2026.pdf
const ELAFGIFT = 0.008; // 2026–2027, https://skat.dk/erhverv/afgifter-paa-varer-og-ydelser-punktafgifter/nyhedsbrev-afgifter/midlertidig-nedsaettelse-af-elafgiften-i-2026-og-2027
// Radius' officielle fulde prisliste fra 1. april 2026 (satser uden moms):
// https://app.cerius-radius.dk/assets/files/Radius%20-%201.%20april%202026%20-%20Fuld%20prisliste.pdf
const RADIUS_LAV = 0.1062;
const RADIUS_SOMMER_HOEJ = 0.1593;
const RADIUS_SOMMER_SPIDS = 0.4141;
const RADIUS_VINTER_HOEJ = 0.3185;
const RADIUS_VINTER_SPIDS = 0.9556;
const SATSER_FRA = '2026-04-01';
const SATSER_TIL = '2027-01-01'; // Stop totalpriser, indtil årets satser er kontrolleret.
const CACHE_SEKUNDER = 900;
const VEJR_CACHE_SEKUNDER = 1800;
const VEJR_LAT = 55.583;
const VEJR_LON = 12.300;
