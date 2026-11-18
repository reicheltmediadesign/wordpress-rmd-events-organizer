# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.1.0] – Unreleased

### Added

- **Events** with any number of dates: single dates, dates over several days, and repeating dates by rule (daily, weekly on chosen weekdays, monthly on a day or on the nth weekday, yearly), with excluded days and cancelled dates.
- **Market dates** as a second kind of event, with daily stall hours, stall position and their own list.
- **Locations** with address, reusable across events and created from the event form.
- **Blocks and shortcodes**: Events (`[rmd_events]`), Market Dates (`[rmd_markets]`), Events Calendar (`[rmd_events_calendar]`, as month calendar or small calendar) and Event Details (`[rmd_event]`).
- Event categories with colours, and event tags.
- Calendar file download (.ics) for upcoming dates and schema.org `Event` structured data.
- **Components** (locations, market dates, calendar) can be switched off per site, or fixed in `wp-config.php` with `RMD_EO_MODULES`.
- Access through the capability `rmd_eo_manage_events`, assignable to roles in the settings.
