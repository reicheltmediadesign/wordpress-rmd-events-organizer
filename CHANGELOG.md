# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.3.2] – 2026-11-19

### Fixed

- Lists no longer repeat the city after a location whose name already says it (“Meißen, Meißen”, “Kreuzgang Meißen, Meißen”).

## [0.3.1] – 2026-11-19

### Added

- **Filters above the list of events**: upcoming, past, cancelled or not taking part, without a date; by category and by location. The filter by the month an event was written in is gone.

### Changed

- **Status per date**: every single date has its own choice “Takes place”, “We are not taking part” (market dates) or “Cancelled” in its box under “When”, also for dates that are over. The checkbox for the whole market under “Details” is gone; where it was set it can still be taken back.

## [0.3.0] – 2026-11-19

### Added

- **Market dates: “We are not taking part”.** A market that takes place without your stall can be marked as such, as a whole or for single dates. It stays in the lists and is labelled, separate from an event the organiser has cancelled.
- **Coloured stripes** in the list of events and beside the dates in the editor: grey for dates that are over, green for dates to come, red for dates to come that are cancelled or without you.

### Changed

- The menu is called “Events and markets” while market dates are switched on.
- Editor: the kind is chosen with two large buttons; day, times and “All day” of a date are in one row; the fields under “Details” are in an even grid.

## [0.2.0] – 2026-11-19

### Added

- **CSV import** (Events → CSV import): creates events and market dates from a CSV file, one date per line, with locations, categories, tags and details. A preview lists what would be created and every line that cannot be used; a key column joins several dates into one event and lets a file be imported again without duplicates. Column names in English or German, a template can be downloaded.

## [0.1.0] – 2026-11-19

### Added

- **Events** with any number of dates: single dates, dates over several days, and repeating dates by rule (daily, weekly on chosen weekdays, monthly on a day or on the nth weekday, yearly), with excluded days and cancelled dates.
- **Market dates** as a second kind of event, with daily stall hours, stall position and their own list.
- **Locations** with address, reusable across events and created from the event form.
- **Blocks and shortcodes**: Events (`[rmd_events]`), Market Dates (`[rmd_markets]`), Events Calendar (`[rmd_events_calendar]`, as month calendar or small calendar) and Event Details (`[rmd_event]`).
- Event categories with colours, and event tags.
- Calendar file download (.ics) for upcoming dates and schema.org `Event` structured data.
- **Components** (locations, market dates, calendar) can be switched off per site, or fixed in `wp-config.php` with `RMD_EO_MODULES`.
- **Import from Events Manager** (component “Import”, off by default): copies events, locations, categories with colours and tags, whether that plugin is active, inactive or deleted. A series becomes one event with a rule; deleted dates become exclusions, changed or cancelled dates are kept, dates edited on their own become separate events. A preview compares every series with the dates that exist; the import can be repeated and undone, and the old event address can be taken over so that existing links keep working.
- **Pages, widgets and menus after the import**: shortcodes, blocks and widgets of Events Manager are changed to those of this plugin (lists, calendars, single events, the events page), menu items lead to the imported events. Everything without a counterpart is listed. Each change keeps the original and can be put back.
- **Polylang**: events, categories and tags have a language; lists and calendars show the events of the language of the page. The import keeps languages and connected translations.
- **Clean up after Events Manager**: optionally removes the tables, settings, posts, categories, widgets and capabilities that plugin leaves behind when it is deleted.
- Access through the capability `rmd_eo_manage_events`, assignable to roles in the settings.
