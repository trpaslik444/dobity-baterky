# Playwright E2E (local)

## Předpoklady
- Node 23+ (lokálně k dispozici)
- `@playwright/test` nainstalovaný v `tests/e2e`
- Stažený prohlížeč: `npx playwright install chromium`
- Přihlašovací cookies k WP (EA/admin) – z DevTools > Application > Cookies: `wordpress_logged_in_*`, `wordpress_*`, `wordpress_sec_*` (pokud je) sloučit do jednoho stringu `name=value; name2=value2`

## Spuštění
```bash
cd tests/e2e
WP_COOKIES="name=value; name2=value2" BASE_URL=http://localhost:10005 npm test
```

## Co testuje `map.spec.js`
- Render `/mapa/` bez console errorů, viditelný `#db-map`, class `db-map-app`
- Načtení admin stránky `tools.php?page=db-nearby-queue` (HTTP 200, nepřesměruje na login, obsahuje text "Nearby Queue"), bez console errorů

Pozn.: Testy jsou autentizované; bez `WP_COOKIES` se celý soubor přeskočí.

## Poznámka k běhu v tomto prostředí
Aktuální spuštění `npm test` selhává na chybě Playwrightu, který očekává binárku `chrome-headless-shell-mac-x64` (arch mismatch). Binárky pro arm64 jsou stažené. Řešení: buď nainstalovat x64 headless shell (`chromium_headless_shell-1200/chrome-headless-shell-mac-x64`) nebo spustit s env `PW_CHROME_BIN=/Applications/Google\\ Chrome.app/Contents/MacOS/Google\\ Chrome` (pokud je Chrome nainstalovaný) a/nebo opravit Playwright arch autodetekci.
