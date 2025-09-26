# Workflow Dobity Baterky

Tento postup sjednocuje lokální vývoj, Git/GitHub, staging prostředí a bezpečné nasazení do produkce.

## 1. Lokální vývoj (Local by Flywheel)
- `git fetch origin main` a `git checkout -b feature/<kratky-popis>` – pracuj vždy v nové větvi.
- Otevři projekt ve **Local**, spusť site `dobity-baterky-dev` a v administraci ověř, že plugin funguje.
- Po úpravách spusť minimálně lint hlavních souborů (stejné příkazy běží i automaticky v GitHub Action `PHP Lint`):
  - `php -l dobity-baterky.php`
  - `find includes -name '*.php' -exec php -l {} +`
- Pokud vše projde, commituj srozumitelnou zprávou a pushni větev na GitHub.

## 2. Build artefaktu pro nasazení
- V kořenovém adresáři pluginu spusť `php build-simple.php` nebo použij skripty v kroku 3/6.
- Výstupní složka je `build/dobity-baterky/` a ZIP `build/dobity-baterky-<verze>.zip` – ZIP se hodí pro manuální instalaci v adminu.
- Build skript zachovává `error_log()` volání, takže nehrozí poztrácení závorek jako minule.
- Proměnné prostředí si můžeš připravit v `.env` (na základě `.env.example`) a načíst `source scripts/load-env.sh`.

## 3. Nasazení na staging (wpcomstaging)
### Automatický skript
- Načti hesla `source scripts/load-env.sh` (nebo ručně nastav `STAGING_PASS`).
- Spusť `./scripts/deploy-staging.sh` z kořene pluginu.
  - Skript: vytvoří nový build, přes SFTP přejmenuje aktuální staging verzi na `dobity-baterky.backup-<timestamp>`, nahraje novou složku a vypíše název zálohy.
  - Používá klíč `~/.ssh/id_ed25519_wpcom` (fingerprint `SHA256:hdZ/y1/0oYcxVFgUe/Kq4epQHUGzbA7Z8KBAHBuVAMc`).

### Ruční postup (fallback)
1. `ssh staging-f576-dobitybaterky.wordpress.com@ssh.wp.com`
2. `sftp -oIdentityFile=~/.ssh/id_ed25519_wpcom -oIdentitiesOnly=yes staging-f576-dobitybaterky.wordpress.com@sftp.wp.com`
3. `cd wp-content/plugins`
4. `rename dobity-baterky dobity-baterky.backup-$(date +%Y%m%d%H%M%S)`
5. `mkdir dobity-baterky`
6. `lcd /Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky/build/dobity-baterky`
7. `put -r * dobity-baterky/`
8. Přihlas se do staging WP administrace, aktivuj plugin a otestuj mapu i admin.

Rollback: `rename dobity-baterky dobity-baterky.failed-$(date +%Y%m%d%H%M%S)` a `rename dobity-baterky.backup-<timestamp> dobity-baterky`.

## 4. Kontrola a testy na stagingu
- Ověř `/wp-admin/`, mapové stránky a kritické funkce (importy, REST, formuláře).
- Pokud narazíš na chybu, nech staging ve stavu „failed“ a oprav branch – staging zůstává izolovaný od produkce.

## 5. GitHub PR a code review
- Po úspěchu na stagingu vytvoř Pull Request proti `main`.
- Do popisu PR uveď:
  - přehled změn,
  - jaké testy proběhly lokálně,
  - odkaz na staging testy.
- Po schválení PR `git merge/pull` zpět lokálně a připrav produkční release.

## 6. Nasazení do produkce
- Doporučený postup: `source scripts/load-env.sh` (pro `PROD_PASS`) a `./scripts/deploy-production.sh`.
  - Skript udělá build, zálohu (`dobity-baterky.backup-<timestamp>`) a upload přes SFTP (`dobitybaterky.wordpress.com@ssh.wp.com`).
- Alternativa: nahrát ZIP z `build/dobity-baterky-<verze>.zip` ručně v administraci (Pluginy → Přidat nový → Nahrát plugin).
- Ponech si poslední dvě zálohy (`*.backup-*`), starší můžeš smazat.

## 7. Automatizace (next steps)
- Přidat CI (GitHub Actions) s `php -l` a případně `phpcs`.
- Připravit jednoduchý deploy skript (bash/expect), který provede kroky 2–3 automaticky.
- Volitelně: GitHub Action pro balíček do Releases – staging i produkci pak můžeš stahovat přímo ze ZIPu.

Dodržováním tohoto workflow minimalizuješ riziko syntax chyb na produkci a máš rychlý rollback přes přejmenované zálohy.
