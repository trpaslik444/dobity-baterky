# Workflow obohacení POI o externí data

Tento dokument popisuje, jak v pluginu Dobitý baterky pracovat s novými integračními možnostmi pro Google Places a Tripadvisor.

## 1. Předpoklady

- V administraci WordPressu musí být nastavené API klíče:
  - `db_google_api_key` – používaný již dříve.
  - `db_tripadvisor_api_key` – nový klíč pro Tripadvisor Content API.
- Pro každé POI je potřeba vyplnit alespoň jeden z externích identifikátorů (viz níže).

## 2. Úprava existujících POI

1. Otevřete detail POI v administraci a v metaboxu **POI – Detaily** doplňte:
   - **Google Place ID** (pokud je k dispozici) – ideálně z odkazu `https://maps.google.com/?cid=...`.
   - **Tripadvisor Location ID** – číslo z URL detailu na TripAdvisoru.
   - **Preferovaný zdroj dat** – ve výchozím stavu Google Places, lze přepnout na Tripadvisor.
2. Uložte příspěvek. Při změně ID se automaticky smažou stará cache data.
3. Při dalším otevření POI v mapě se data načtou z API a uloží na maximální dobu povolenou licencí:
   - Google Places: 30 dní.
   - Tripadvisor: 24 hodin.

## 3. Nově přidávané POI

1. Po vytvoření základního záznamu POI v administraci použijte vyhledání v Google Places (existující UI) a uložte Place ID.
2. Pokud je dostupný také TripAdvisor Location ID, vyplňte ho jako záložní zdroj.
3. Vyberte preferovaný zdroj (Google > Tripadvisor).
4. Při prvním otevření v mapě se data stáhnou, vyplní kontakty a uloží se cache.

## 4. Jak funguje načítání v mapě

- Po kliknutí na POI se nejdříve stáhnou interní data z REST endpointu `/db/v1/map`.
- Pokud je POI starší nebo cache expirovala, mapa zavolá nový endpoint `/db/v1/poi-external/{id}`.
- Endpoint podle preferencí zavolá nejdříve Google, po vyčerpání limitu nebo chybě zkusí Tripadvisor.
- Úspěšně načtená data se uloží do post meta (kontakt, web, hodnocení, fotky, sociální sítě) a zároveň se vrátí do frontendu.
- Frontend okamžitě zobrazí doplněné kontakty, weby a fotky. Vlastnost `poi_external_expires_at` určuje, kdy je potřeba data obnovit.

## 5. Limity a logika limitů

- Každý externí zdroj má vlastní denní čítač (výchozí limit Google 900, Tripadvisor 500 dotazů/den).
- Limity lze upravit filtrem `db_poi_service_daily_limit( $limit, $service )`.
- Pokud je limit dosažen, endpoint vrátí `provider: null` a do `errors` uvede důvod. Frontend zůstane u poslední cache.

## 6. Úklid cache

- Při změně Place ID / Location ID se cache automaticky maže.
- Expirovaná cache se při dalším dotazu přepíše novými daty.
- Ruční smazání je možné vymazáním meta `_poi_google_cache` nebo `_poi_tripadvisor_cache`.

## 7. Sociální sítě

- Pokud TripAdvisor vrátí sociální odkazy, uloží se do meta `_poi_social_links` a zobrazí se v detailu.
- Google Places aktuálně sociální odkazy neposkytuje, pole se nechává prázdné.

