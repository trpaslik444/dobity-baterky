# Places enrichment safeguards

The Google Places enrichment flow is guarded by an application-level cap and feature flag to avoid unexpected charges.

## Configuration

- `MAX_PLACES_REQUESTS_PER_DAY` – hard cap enforced in code before every Places call (default: 300). When the limit is reached the API returns `{ enriched: false, reason: "quota_exceeded" }` and Google is not contacted.
- `PLACES_ENRICHMENT_ENABLED` – feature flag to disable all Places enrichment calls instantly (defaults to `true`).
- `PLACES_ENRICHMENT_CACHE_DAYS` – minimum age (in days) before a POI can be enriched again unless forced (default: 7 days).

These values are read from the environment so they can be tuned without code changes.

## Operational notes

- Usage counters are stored in the `db_places_usage` table and incremented inside a transaction before any Google Places call is made.
- When usage reaches ~80 % of the configured cap a warning is logged with the `PLACES_ENRICHMENT` tag, ready for integration with a notification channel.
- Enrichment requests are deduplicated per request and skipped entirely when the feature flag is disabled or when the POI was enriched recently.
- A panic switch is available via `PLACES_ENRICHMENT_ENABLED=false` to stop all Places calls from the application layer without touching the Google Cloud console.

## Key safety

Even with the in-app guardrails, secure the Google Places API key in Cloud Console:

- Restrict the key by HTTP referrer or server IP.
- Disable unused APIs and set billing budgets/alerts.
- Keep a daily cap in Google Cloud to complement the in-app limit.

## Batch enrichment guidance

Automated batch jobs should call the central `Places_Enrichment_Service` so the quota checks, deduplication, and panic switch are respected. Always process POIs in small batches and honour the `MAX_PLACES_REQUESTS_PER_DAY` budget.
