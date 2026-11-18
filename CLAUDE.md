# RMD Events Organizer – Projektregeln

WordPress-Plugin von reichelt media.design für Kunden-Websites, Ersatz für „Events Manager“. Repo: `github.com/reicheltmediadesign/wordpress-rmd-events-organizer`, Lizenz GPL-2.0-or-later, Autor Philipp Reichelt.

Events Manager dient nur als Orientierung: kein Code, keine Syntax, keine Platzhalter von dort übernehmen. Der vollständige Plan (inkl. Migration v0.2/v0.3 und späterer Buchung) liegt unter `C:\Users\phili\.claude\plans\sieh-dir-das-plugin-proud-wall.md`.

## Aufbau

- `rmd-events-organizer.php` Bootstrap (muss PHP-7-parsebar bleiben), `includes/` PSR-4 `RMD\EventsOrganizer\`.
- `includes/Domain/` ist **WordPress-frei** und wird mit plain PHPUnit getestet. `Domain\Validator` ist die einzige Instanz, die Datenformen definiert (Terminplan, Details, Adresse), `Domain\Settings` die der Einstellungen. Jeder Schreibpfad läuft dort durch.
- CPTs `rmd_eo_event` und `rmd_eo_location`, Taxonomien `rmd_eo_category` (Term-Meta `rmd_eo_color`) und `rmd_eo_tag`. Typ einer Veranstaltung (`event` | `market`) als Meta `_rmd_eo_kind`, Felder pro Typ in `Domain\Kinds`.
- **Terminplan** = Meta `_rmd_eo_schedule` (einzige Wahrheit): `entries` (`single` | `rule`, jede mit stabiler `id`), `exclusions`, `overrides`. `Domain\Schedule::expand()` erzeugt daraus Termine; `Domain\RecurrenceExpander` kennt nur Regeln und Daten (wird später für Terminbuchung wiederverwendet).
- **Tabelle `{prefix}rmd_eo_occurrences`** ist ein abgeleiteter Index (`Schema`). `Sync\OccurrenceSync` gleicht per `occ_key` ab (`<eintrag>` bzw. `<eintrag>:<Y-m-d>`), damit Zeilen-IDs stabil bleiben. Lokale Zeit ist die Wahrheit, UTC abgeleitet. Lesen ausschließlich über `Query\OccurrenceQuery`.
- **Module** (`Modules`): `locations`, `markets`, `calendar`, `migration`. Reihenfolge Default → Einstellung → Konstante `RMD_EO_MODULES` (sperrt den Schalter) → Filter `rmd_eo_modules`. Neue Module: Name in `Domain\Settings::MODULES` und `defaults()`, Konstante in `Modules`, Schalter in `Admin\SettingsPage`, Prüfung `Modules::is_on()` an den Einstiegspunkten.
- **Import aus Events Manager** (Modul `migration`, v0.2): `Migration\EmReader` ist die einzige Klasse, die die `em_*`-Tabellen liest (nur lesend; beide Schema-Generationen, vor 7.0 mit Regelspalten und ab 7.0 mit `event_type`/Regeltabelle, werden in dieselbe Form gebracht). `Migration\Planner` baut daraus Einheiten (eine Einheit = eine Veranstaltung hier), `Domain\Em\SeriesCollapser` fasst Serien zu Regel + Ausnahmen + Overrides zusammen und vergleicht das Ergebnis mit den vorhandenen Terminen (Paritätsprüfung, Abnahmekriterium: keine Abweichung). `Migration\Importer` schreibt idempotent über die Quell-IDs (`_rmd_eo_em_event_id`, `_rmd_eo_em_post_id`, `_rmd_eo_em_location_id`, Term-Meta `rmd_eo_em_term_id`) und kann alles Importierte wieder löschen. Noch offen (v0.3): EM-Shortcodes/-Blöcke/-Widgets in Inhalten umschreiben, `CONTENTS`-Seiten, Polylang-Sprachen, Menüeinträge, EM-Reste entfernen. Das alte Schema (5.x/6.x) ist umgesetzt, aber mangels Daten nur über Unit-Tests der Regel-Abbildung geprüft, nicht gegen eine echte Datenbank.
- **Zugriff**: Capability `rmd_eo_manage_events` (Administrator immer, weitere Rollen über Einstellung `roles`); Einstellungen nur mit `manage_options`. Capabilities regeln, wer etwas darf; Module, ob es die Funktion gibt.
- Admin-Editor: React-App (`src/admin/event-editor`) unter dem Titel (`edit_form_after_title`), Zustand als JSON im hidden Feld `rmd_eo_data`; PHP validiert in `Admin\SaveHandler`. Die Wiederholungslogik existiert nur in PHP, die Vorschau kommt über `POST rmd-events-organizer/v1/preview` (schreibt nichts).
- Blocks: `src/blocks/*` (`rmd/events`, `rmd/market-dates`, `rmd/events-calendar`, `rmd/event-details`) mit `block.json` + `render.php`; Shortcodes spiegeln sie und nutzen dieselben Renderer (`includes/Render/`). Front-Assets liegen ungebaut in `assets/`.
- Cache-Sicherheit: Listen tragen `data-end`, `assets/js/front.js` blendet Abgelaufenes aus; Kalender blättert über `GET rmd-events-organizer/v1/calendar` und fällt ohne JS auf Links (`?eo_month=`, `?eo_day=`) zurück.
- `Admin\HelpPage` ist die Nutzer-Doku im Backend; bei neuen Feldern, Attributen oder Blöcken dort **und** in README/PO nachziehen.

## Code-Regeln

- PHP 8.1+, WP 6.8+. WordPress-Coding-Standards (`phpcs.xml.dist`), Methoden snake_case, Präfix `rmd_eo_` / `RMD_EO_` / Namespace `RMD\EventsOrganizer`, CSS `rmd-eo-`, Textdomain `rmd-events-organizer`.
- Quellstrings Englisch, Übersetzung in `languages/rmd-events-organizer-de_DE.po` von Hand nachziehen (`npm run i18n` erzeugt POT/MO/JSON; braucht WP-CLI, läuft im Workflow). Die Übersetzung siezt; `de_DE_formal` erzeugt `make-i18n.sh` daraus.
- Sicherheit: jede Schreiboperation prüft Nonce + Capability; Ausgabe escapen; REST schreibt nichts; keine externen Aufrufe außer Update-Check.
- Version an vier Stellen: Plugin-Header, `RMD_EO_VERSION`, `package.json`, `readme.txt` (Stable tag) und `src/blocks/*/block.json`. `bash bin/check-version.sh` prüft das.
- Schemaänderungen: `Schema::VERSION` erhöhen (dbDelta läuft über `Upgrade::run`); Datenmigrationen als Schritt in `Upgrade`.
- `RMD_EO_FAKE_NOW` (nur mit `WP_DEBUG`) in `wp-config.php` erlaubt Zeitreisen zum Testen.

## Build und Prüfung

```powershell
npm run build          # Blocks + Admin-Editor nach build/
npm run lint           # JS + SCSS
npm run test:js        # Jest (Editor-Modell)
composer phpcs         # PHPCS
composer test          # PHPUnit (Domain)
npm run zip            # dist/rmd-events-organizer.zip (Git Bash: rsync, zip, composer nötig)
```

Composer liegt unter `C:\xampp\php\composer.bat`.

Testen in WordPress: nie in Kundenseiten. Die Testseite ist `C:
mpp\htdocs	estwordpress` (`http://localhost/testwordpress/`); `bin/link-local.ps1 -WordPressPath "C:
mpp\htdocs	estwordpress"` bindet das Repo per Junction ein (dort ist auch Events Manager installiert). Demo-Daten im Format von Events Manager 7.x legt `php tests/fixtures/em/seed-demo.php "C:/xampp/htdocs/testwordpress"` an (alles mit `[em-demo]` im Namen, wird bei jedem Lauf ersetzt); für echte Altbestände mit Datenbank-Klonen arbeiten.

## Git und Release

- Committen nur auf Anfrage, auf Feature-Branches; nie direkt auf `main`.
- Release: Versionen an allen Stellen erhöhen, `CHANGELOG.md` und `readme.txt` (Changelog) ergänzen, committen, Tag `vX.Y.Z` pushen. Der Workflow lintet, testet, baut Assets + Übersetzungen, erzeugt `rmd-events-organizer.zip` sowie `update.json` und legt ein **Draft-Release** an. Beide Assets müssen am Release bleiben (installierte Sites lesen `releases/latest/download/update.json`).
- Den vom Workflow angelegten Entwurf bearbeiten (Releases → Entwurf → Stift), nicht neu anlegen; erst nach Test des ZIPs veröffentlichen.
- Bei jedem Release Titel `vX.Y.Z – <Kernänderung>` und Release Notes (Englisch, Markdown in Codeblock) mit ausgeben; Grundlage `git log <letzter Tag>..HEAD`.
- Topics fürs Repo: `wordpress`, `wordpress-plugin`, `gutenberg-block`, `events`, `calendar`.
- Nach dem ersten veröffentlichten Release: Plugin in `rmd-wordpress.git/scripts/new-project/` (Script, Example-Config, lokale Config, README) aufnehmen und den Doku-Block `rmd-docs/blocks/plugins/rmd-events-organizer.md` anlegen.

## README

Nutzerorientiert (Features, Usage, Blocks/Shortcodes, Settings, Styling, Privacy, Installation, License). Kein Development-Abschnitt in der README; Build/Release steht hier.
