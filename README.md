# RMD Events Organizer

A lean WordPress plugin for events and market dates: enter dates once, show them as a list, a month calendar or a small sidebar calendar.

## Features

- **Events with any number of dates.** A single date, several days in a row, or a rule: daily, weekly on chosen weekdays, monthly ("every first Saturday", "every 15th") or yearly. Rules can end on a date, after a number of dates, or never.
- **Exceptions.** Leave out single days or whole periods, or mark one date as cancelled without touching the rule.
- **Market dates.** A second kind of event for markets where your business has a stall: first and last day, the same opening hours every day, the position of your stall, a link to the organiser. Listed together with events or on their own ("Find us here").
- **Locations** with address, reusable across events. A new location can be typed directly into the event form.
- **Categories with colours and tags** to filter lists and calendars.
- **Calendar downloads** (.ics) for upcoming dates and schema.org structured data for search engines.
- **CSV import.** Creates many events or market dates at once from a table, with a preview before anything is written.
- **Import from Events Manager.** Copies events, series, locations, categories and tags, even when that plugin has already been deleted, and changes its shortcodes, blocks, widgets and menu items to those of this plugin. Every step can be undone; removing the old data at the end is optional.
- **Only what a site needs.** Locations, market dates, the calendar and the import are components that can be switched off.
- **Works with page caches and without JavaScript.** Month navigation and the small calendar are plain links that a small script upgrades.

## Usage

The menu is called **Events**, or **Events and markets** while market dates are switched on.

1. Go to **Events → Add event** and enter the name.
2. Under **When** enter the date. Leave the times empty for an all-day event; choose **Repeats** for something regular. The list of next dates shows what you entered and lets you cancel or leave out single dates.
3. Choose a location, add a description and an image, and publish.

The page of an event shows its dates, location and details above the description automatically.

## Blocks and shortcodes

| Block | Shortcode | Shows |
| --- | --- | --- |
| Events | `[rmd_events]` | A list of dates |
| Market Dates | `[rmd_markets]` | The markets where the business has a stall |
| Events Calendar | `[rmd_events_calendar]` | A month calendar; `size="mini"` for the small calendar |
| Event Details | `[rmd_event id="123"]` | Dates, location and details of one event |

### Looks

Every block comes in three looks. Choose one under **Styles** in the block sidebar; shortcodes take the attribute `style`.

| Block | Looks (`style`) |
| --- | --- |
| Events, Market Dates | Calendar sheet (`default`): rows with a calendar sheet beside each date. Cards (`cards`): every date in its own box. Plain (`plain`) |
| Events Calendar | Sheet (`default`): one panel, events as tinted labels. Tiles (`tiles`): every day its own tile, days with dates tinted. Plain (`plain`) |
| Event Details | Card (`default`): dates and location side by side in a box. Banner (`banner`): the dates on the accent colour. Plain (`plain`) |

"Plain" is the bare layout without boxes, shadows and colours, meant as the base for your theme's own stylesheet. The page of a single event takes its look from **Settings → Look of the event page**. The colour of calendar sheets, marked days and buttons is the **accent colour** in the settings.

### Attributes of `[rmd_events]` and `[rmd_markets]`

| Attribute | Values | Default |
| --- | --- | --- |
| `scope` | `upcoming`, `past`, `all`, `today`, `tomorrow`, `month`, `next-month` | `upcoming` |
| `from`, `to` | A fixed period, each as `YYYY-MM-DD` | – |
| `limit` | Number of dates | Setting "Dates per list" |
| `kind` | `event` or `market` | both |
| `category`, `tag` | Slugs, several separated by commas | – |
| `exclude_category` | Category slugs to leave out | – |
| `location` | Id of a location | – |
| `unique` | `yes`: each event once, with its next date | `no` |
| `order` | `asc` or `desc` | by scope |
| `group` | `month` or `year`: adds headings | – |
| `show` | `date`, `time`, `location`, `stall`, `excerpt`, `image`, `category` | `date,time,location` |
| `pagination` | `yes`: links to earlier and later dates | `no` |
| `empty` | Text shown when there are no dates | – |
| `style` | `default`, `cards` or `plain` | `default` |
| `class` | Additional CSS classes | – |

### Attributes of `[rmd_events_calendar]`

| Attribute | Values | Default |
| --- | --- | --- |
| `size` | `full` or `mini` | `full` |
| `style` | `default`, `tiles` or `plain` | `default` |
| `month` | Month to start with, `YYYY-MM` | current month |
| `kind`, `category`, `tag`, `location` | As above | – |
| `class` | Additional CSS classes | – |

`[rmd_event]` takes `id`, `title` (`yes` or `no`), `style` (`default`, `banner` or `plain`) and `class`.

Days in the small calendar link to the page chosen under **Settings → Events page**, filtered to that day. With JavaScript they open a small list of that day's dates instead.

## Multilingual sites (Polylang)

With Polylang active, events, categories and tags have a language and can be translated like pages. Lists and calendars show the events in the language of the page they are on; a single event placed with the block **Event Details** is shown in any language. Locations are shared by all languages. Events without a language do not appear in lists, so assign one to existing events when Polylang asks for it.

## CSV import

**Events → CSV import** creates events and market dates from a CSV file, for example a list of the year's markets. Choose the file to get a preview: it lists the events that would be created, and every line that cannot be used with the reason. Nothing is written before you confirm. **Download a template** gives you a file with all column names.

Every line is one date. The first line names the columns; only `title` and `date` are needed, the order does not matter. The German names in brackets work as well.

| Column | Content |
| --- | --- |
| `title` (`titel`) | Name of the event. Required. |
| `date` (`datum`) | Day, or first day: `12.12.2026` or `2026-12-12`. Required. |
| `end_date` (`enddatum`) | Last day, only for dates over several days. |
| `start` (`beginn`), `end` (`ende`) | Times such as `10:00`. Without a start the date is all day. |
| `daily_hours` (`zeiten_taeglich`) | `yes` / `ja`: over several days the times apply to every day. Empty: from the start on the first day to the end on the last. |
| `key` (`schluessel`) | Any short name of your own. Lines with the same key become one event with several dates, described by the first of these lines. Importing a file again updates events that have a key instead of creating them again. |
| `kind` (`art`) | `event` / `veranstaltung` (default) or `market` / `markt`. |
| `description` (`beschreibung`) | Text of the event. |
| `location` (`ort`) | Name of the location. An existing location of that name is used, otherwise one is created. |
| `street` (`strasse`), `postcode` (`plz`), `city` (`stadt`), `country` (`land`) | Address of a new location; the country as two letters. |
| `categories` (`kategorien`), `tags` (`schlagwoerter`) | Names, several separated by commas. Missing ones are created. |
| `organizer` (`veranstalter`), `organizer_url` (`veranstalter_url`), `info_url`, `price` (`preis`) | Details of the event. Addresses must start with `https://`. |
| `stall` (`stand`), `stall_note` (`stand_hinweis`) | Market dates: where your stall is, and a note about it. |
| `status` | `published` / `veroeffentlicht` (default) or `draft` / `entwurf`. |
| `image` (`bild`) | Id or address of an image that is already in the media library. |
| `language` (`sprache`) | Code of the language, only with Polylang. |

Columns are separated by semicolons or commas; save the file as “CSV UTF-8”. When a file is imported again, the dates of an event with a key are replaced by those of the file (dates cancelled by hand stay cancelled), and every other value is only changed where the file has one. Repeating dates cannot be imported; enter them in the event itself.

## Import from Events Manager

Switch on the component **Import** under **Events → Settings**, then open **Events → Import**. The page has three steps.

### 1. Events

1. The page shows what was found and what the import would create. Every series is checked: the dates it will have afterwards are compared with the dates Events Manager created.
2. **Import now** copies events, locations, categories (with their colours) and tags. A series becomes one event with a repeating rule. Dates that were deleted from a series are left out, dates with other times or a cancellation keep them, and a date that was given its own title or text becomes an event of its own.
3. With Events Manager deactivated, **Take over the old address** makes events available under the address they had before. The addresses of the single dates of a series lead to the series.

Both plugins can run side by side while you compare. If they use the same address (for example `/veranstaltungen/`), Events Manager keeps it as long as it is active and the events of this plugin are available under `/veranstaltungen-2/`; they move to the real address by themselves once Events Manager is deactivated.

The data of Events Manager is only read. You can import again at any time (imported events are updated, not duplicated) or undo the import, which deletes everything it created.

Not imported: bookings and tickets, events in the trash, time slots, and the list and page formats of Events Manager.

### 2. Pages, widgets and menus

Lists every page, post, template, widget and menu item that still uses something of Events Manager, and shows what it would become:

| Events Manager | Becomes |
| --- | --- |
| `[events_list]`, `[events_list_grouped]`, block “Events List”, widget “Events” | `[rmd_events]` or the block **Events**, with period, number, category, tag, location and paging carried over |
| `[events_calendar]`, block “Events Calendar”, widget “Events Calendar” | `[rmd_events_calendar]` or the block **Events Calendar** (small calendar unless the old one was the full one) |
| `[event]` | `[rmd_event]` with the imported event |
| The events page (content `CONTENTS`) | The list of events with paging |
| Menu items leading to an event or to the events page | Lead to the imported event or to that page |

Formats written into a shortcode (`#_EVENTNAME` and the like) are not carried over; the display follows this plugin. Shortcodes without a counterpart (lists and maps of locations, lists of categories and tags, search and submission forms, bookings) stay as they are and are listed, so you can remove them by hand. The same goes for menu items leading to locations or categories.

**Change** works per page, **Change all** for everything at once. The original content is kept for every change; **Put back** restores it.

On a site with Polylang the imported events, categories and tags keep their language, and translations stay connected.

### 3. Clean up

Events Manager leaves its tables, settings, posts, categories and capabilities in the database when it is deleted. This optional step removes them once that plugin is deactivated. It shows what would be removed and asks you to type a confirmation. It cannot be undone, so make a backup first; afterwards the import can no longer be repeated or undone. Imported events and images are kept.

## Settings

**Events → Settings** (administrators only).

| Setting | Meaning |
| --- | --- |
| Components | Switch locations, market dates, the calendar and the import on or off |
| Dates per list | Default number of dates in lists |
| End time | Show the end time of events |
| Event page | Add dates, location and details to the page of an event automatically |
| Look of the event page | Card, Banner or Plain for those dates and details |
| Accent colour | Colour of calendar sheets, marked days and buttons; a dark grey when not set |
| Calendar file | Offer "Add to calendar" (.ics) |
| Structured data | Output schema.org JSON-LD on event pages |
| Repeat ahead (months) | How far ahead dates of rules without an end are created |
| Events page | Target of the days in the small calendar |
| Address of events / locations | URL base of event and location pages |
| Location pages | Give locations their own public page |
| Roles | Roles that may manage events besides administrators |
| Delete data | Remove all data when the plugin is deleted |

Components can also be fixed for a site in `wp-config.php`; the switch is then locked on the settings page:

```php
define( 'RMD_EO_MODULES', [ 'markets' => true, 'calendar' => false ] );
```

Access is controlled by the capability `rmd_eo_manage_events`.

## Styling

The output uses classes prefixed with `rmd-eo-`. Each list, calendar and event box carries the class of its look: `rmd-eo-list--default|cards|plain`, `rmd-eo-calendar--default|tiles|plain` (besides `--full|mini`) and `rmd-eo-event--default|banner|plain`. For a design of your own choose "Plain" and style from there.

These custom properties adjust the looks; set them on `.rmd-eo`:

| Property | Purpose |
| --- | --- |
| `--rmd-eo-accent` | Calendar sheets, marked days, buttons, line beside events (default: a dark grey, in the plain looks the text colour; the setting "Accent colour" sets it too) |
| `--rmd-eo-on-accent` | Text on the accent colour (default: white) |
| `--rmd-eo-accent-fill` | Fill of marked days in the small calendar (plain look: none) |
| `--rmd-eo-accent-text` | Text of marked days in the small calendar |
| `--rmd-eo-surface` | Background of cards, calendars and boxes (default: white) |
| `--rmd-eo-on-surface` | Text on that background (default: inherited) |
| `--rmd-eo-shadow` | Shadow of cards, calendars and boxes |
| `--rmd-eo-border` | Lines between items and calendar cells |
| `--rmd-eo-muted` | Secondary text |
| `--rmd-eo-cancelled` | Colour of the "Cancelled" label |
| `--rmd-eo-gap` | Space between list items |
| `--rmd-eo-pad` | Inner space of cards and boxes |
| `--rmd-eo-radius` | Corner radius |

Events carry `--rmd-eo-event-color` when their category has a colour.

```css
.rmd-eo {
	--rmd-eo-accent: #8a1c1c;
	--rmd-eo-radius: 0;
}
```

## Privacy

The plugin stores events, locations and settings in the site's database. It does not collect visitor data, sets no cookies and loads nothing from other servers. The only outgoing request is the update check against the plugin's GitHub releases.

## Installation

Download `rmd-events-organizer.zip` from the [latest release](https://github.com/reicheltmediadesign/wordpress-rmd-events-organizer/releases/latest) and upload it under **Plugins → Add New → Upload**. Updates arrive through the normal WordPress update screen.

Requires WordPress 6.8 and PHP 8.1 or newer.

## License

GPL-2.0-or-later. © Philipp Reichelt, [reichelt media.design](https://reicheltmedia.design)
