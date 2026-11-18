# RMD Events Organizer

A lean WordPress plugin for events and market dates: enter dates once, show them as a list, a month calendar or a small sidebar calendar.

## Features

- **Events with any number of dates.** A single date, several days in a row, or a rule: daily, weekly on chosen weekdays, monthly ("every first Saturday", "every 15th") or yearly. Rules can end on a date, after a number of dates, or never.
- **Exceptions.** Leave out single days or whole periods, or mark one date as cancelled without touching the rule.
- **Market dates.** A second kind of event for markets where your business has a stall: first and last day, the same opening hours every day, the position of your stall, a link to the organiser. Listed together with events or on their own ("Find us here").
- **Locations** with address, reusable across events. A new location can be typed directly into the event form.
- **Categories with colours and tags** to filter lists and calendars.
- **Calendar downloads** (.ics) for upcoming dates and schema.org structured data for search engines.
- **Import from Events Manager.** Copies events, series, locations, categories and tags, even when that plugin has already been deleted. Nothing is changed in the old data; the import can be repeated and undone.
- **Only what a site needs.** Locations, market dates, the calendar and the import are components that can be switched off.
- **Works with page caches and without JavaScript.** Month navigation and the small calendar are plain links that a small script upgrades.

## Usage

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
| `class` | Additional CSS classes | – |

### Attributes of `[rmd_events_calendar]`

| Attribute | Values | Default |
| --- | --- | --- |
| `size` | `full` or `mini` | `full` |
| `month` | Month to start with, `YYYY-MM` | current month |
| `kind`, `category`, `tag`, `location` | As above | – |
| `class` | Additional CSS classes | – |

Days in the small calendar link to the page chosen under **Settings → Events page**, filtered to that day. With JavaScript they open a small list of that day's dates instead.

## Import from Events Manager

Switch on the component **Import** under **Events → Settings**, then open **Events → Import**.

1. The page shows what was found and what the import would create. Every series is checked: the dates it will have afterwards are compared with the dates Events Manager created.
2. **Import now** copies events, locations, categories (with their colours) and tags. A series becomes one event with a repeating rule. Dates that were deleted from a series are left out, dates with other times or a cancellation keep them, and a date that was given its own title or text becomes an event of its own.
3. With Events Manager deactivated, **Take over the old address** makes events available under the address they had before. The addresses of the single dates of a series lead to the series.

The data of Events Manager is only read. You can import again at any time (imported events are updated, not duplicated) or undo the import, which deletes everything it created.

Not imported: bookings and tickets, events in the trash, time slots, and the list and page formats of Events Manager. Pages that contain shortcodes of Events Manager have to be changed to the blocks or shortcodes of this plugin by hand.

## Settings

**Events → Settings** (administrators only).

| Setting | Meaning |
| --- | --- |
| Components | Switch locations, market dates, the calendar and the import on or off |
| Dates per list | Default number of dates in lists |
| End time | Show the end time of events |
| Event page | Add dates, location and details to the page of an event automatically |
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

The output uses classes prefixed with `rmd-eo-` and these custom properties, which you can set on `.rmd-eo` or any parent:

| Property | Purpose |
| --- | --- |
| `--rmd-eo-accent` | Marker colour: ring around days with dates in the small calendar, line beside events |
| `--rmd-eo-accent-fill` | Fill of marked days in the small calendar (default: none) |
| `--rmd-eo-accent-text` | Text of marked days (default: inherited) |
| `--rmd-eo-border` | Lines between items and calendar cells |
| `--rmd-eo-muted` | Secondary text |
| `--rmd-eo-cancelled` | Colour of the "Cancelled" label |
| `--rmd-eo-gap` | Space between list items |
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
