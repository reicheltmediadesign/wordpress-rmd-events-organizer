=== RMD Events Organizer ===
Contributors: reicheltmediadesign
Tags: events, calendar, market, recurring events, block
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events and market dates with repeating schedules, shown as lists and calendars via blocks and shortcodes.

== Description ==

RMD Events Organizer is a lean events plugin for small business websites:

* Events with any number of dates: single dates, several days, or repeating by rule (daily, weekly on chosen weekdays, monthly like "every first Saturday", yearly), with exceptions and cancelled dates.
* Market dates: enter the markets where the business has a stall, with daily hours and the position of the stall, and list them separately.
* Locations with address, reusable across events and created right in the event form.
* Blocks and shortcodes: a list of dates, a month calendar, a small calendar for sidebars that only marks the days, and the details of a single event.
* Categories with colours and tags to filter lists and calendars.
* "Add to calendar" downloads (.ics) and schema.org structured data.
* CSV import: many events or market dates at once from a table, with a preview.
* Import from Events Manager: events, series, locations, categories and tags, with a preview, repeatable and undoable. Shortcodes, blocks, widgets and menu items of that plugin are changed to those of this one, and its leftovers can be removed from the database.
* Components can be switched off per site; access is controlled by one capability that can be given to roles.
* Works with page caching and without JavaScript.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload, or copy the folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Go to Events → Add event and enter your first date.
4. Add the blocks to a page or use the shortcodes listed under Events → Help.

== Frequently Asked Questions ==

= Which shortcodes are available? =

`[rmd_events]`, `[rmd_markets]`, `[rmd_events_calendar]`, `[rmd_events_calendar size="mini"]` and `[rmd_event id="123"]`. Events → Help lists all attributes.

= Does it work with caching plugins? =

Yes. Lists carry the end time of each date, and a small script hides dates that have passed since the page was cached. Known page caches are also purged when an event is saved and once a day.

= How do I switch components off for a site? =

Under Events → Settings → Components, or fixed in `wp-config.php`: `define( 'RMD_EO_MODULES', [ 'markets' => false, 'calendar' => true ] );`.

== Changelog ==

= 0.4.0 =
* Looks for every block, chosen under “Styles” in the block sidebar: lists as calendar sheet or cards, the calendar as sheet or tiles, event details as card or banner, each also plain for themes with their own stylesheet.
* Settings “Look of the event page” and “Accent colour”.
* Lists, calendars and event pages show the new default look after the update; choose “Plain” for the previous one.

= 0.3.2 =
* Lists no longer repeat the city after a location whose name already says it.

= 0.3.1 =
* Every single date has its own status: takes place, we are not taking part (market dates) or cancelled, also for dates that are over.
* Filters above the list of events: upcoming, past, cancelled or not taking part, without a date, by category and by location.

= 0.3.0 =
* Market dates can be marked as “We are not taking part”, as a whole or for single dates.
* Coloured stripes in the list of events and in the editor show what is over, to come, or cancelled.
* Editor: clearer choice of the kind, dates and times in one row, even grid for the details. The menu is called “Events and markets” while market dates are switched on.

= 0.2.0 =
* CSV import: create many events or market dates at once from a table, with a preview. Importing a file again updates the events that have a key.

= 0.1.0 =
* First release: events, market dates, locations, repeating schedules, list, month calendar, small calendar, event details, calendar downloads and structured data.
* Import from Events Manager, including its shortcodes, blocks, widgets and menu items, and an optional cleanup of its leftovers.
* Works with Polylang on multilingual sites.
