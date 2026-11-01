# School Calendar — Calendarific API integration

SIGLA reads the Philippine public holidays from the [Calendarific API](https://calendarific.com/api-documentation) so the School-Based Feeding Program (SBFP) stops counting a holiday as a feeding day. The System Admin adds class suspensions and school breaks by hand, because no API knows about a typhoon suspension or a Christmas break.

## Why it exists

The SBFP runs for a fixed number of **feeding days** (120 by default, set per school). Before this integration, the app's only rule for "no feeding today" was the weekend (`FeedingProgramCycle::isFeedingDay()` checked `isWeekend()` and nothing else). That caused four errors:

| Where | What went wrong |
|---|---|
| Coordinator's Dashboard and Attendance header | "Feeding day N of 120" counted Bonifacio Day, Christmas and the Christmas break, so it ran ahead of the programme. |
| Cycle end date (`endDateIso()`) | Landed several weeks early, so the app refused real sessions near the end of the cycle as "outside the programme". |
| School Head's Attention queue | "Today's feeding session has not been recorded" appeared on holidays, and on weekends too. |
| Record Attendance date picker | A holiday could be chosen and saved as a feeding session, adding a session nobody held to every at-risk rate. |

After this change, a public holiday, a class suspension and a school break are handled exactly like a weekend. The day is not counted, the date picker locks it, the save endpoint refuses it, and the calendar draws it as closed with its name.

## How it fits together

```
Calendarific API ──► CalendarificClient ──► PublicHolidaySync ──► school_calendar_days ◄── SchoolCalendarController (System Admin: suspensions, breaks)
 (GET /holidays)      (request + validate)   (replace one year,                │
                                              one transaction)                 ▼
                                                                         SchoolCalendar  (one read per request)
                                                                               │
                                                     FeedingProgramCycle::isFeedingDay() / countFeedingDays() / endDateIso()
                                                                               │
                        Dashboard · Attendance tab · Record Attendance · date picker · calendar view · School Head queue
```

| Piece | File | Responsibility |
|---|---|---|
| API client | `app/Support/CalendarificClient.php` | Sends the request, handles failures, and keeps only the holidays that close a school (`interpret()`). |
| Sync | `app/Support/PublicHolidaySync.php` | Replaces one calendar year's holidays in one transaction and writes the audit entry. |
| Reader | `app/Support/SchoolCalendar.php` | The only code that answers "is this school closed on this date, and why". It reads each school's closed dates once per request. |
| Model and table | `app/Models/SchoolCalendarDay.php`, migration `2026_11_01_000001` | One row per closure, covering `starts_on` to `ends_on`. |
| Admin actions | `app/Http/Controllers/SchoolCalendarController.php` | Sync button, add a suspension or break, remove one. |
| Command | `app/Console/Commands/SyncPublicHolidays.php` | `php artisan holidays:sync [year...]`, scheduled monthly. |
| Failure type | `app/Support/HolidayApiFailure.php` | The message an admin is shown when a sync fails. |

## Setup

1. Create a free account at <https://calendarific.com> and copy the API key from the account dashboard. The free plan allows 500 requests a month, and one sync costs one request per calendar year.
2. Add the key to `.env`, or to the Railway service's variables:

   ```env
   CALENDARIFIC_API_KEY=your-key-here
   ```

3. Clear the cached config and apply the migration:

   ```bash
   php artisan config:clear
   php artisan migrate        # creates school_calendar_days
   ```

4. Sync the holidays, either way:
   - **System Admin → Control Center → School Calendar → Sync Holidays**, or
   - `php artisan holidays:sync` (with no argument it syncs both years of the current school year; a school year runs June to May).

### Optional settings

| Variable | Default | Meaning |
|---|---|---|
| `CALENDARIFIC_COUNTRY` | `PH` | ISO 3166-1 country code. |
| `CALENDARIFIC_LOCATION` | *(empty)* | ISO 3166-2 region code. When set, regional holidays for that region are kept too. When empty, only national holidays are kept. |
| `CALENDARIFIC_ENDPOINT` | `https://calendarific.com/api/v2` | Base URL. |
| `CALENDARIFIC_TIMEOUT` | `20` | Request timeout in seconds. |

With no key set, the sync is disabled (the button shows **API key not set**) and every weekday stays a feeding day, as before. Suspensions and breaks entered by hand still work.

## The request

```http
GET https://calendarific.com/api/v2/holidays?api_key=…&country=PH&year=2026&type=national
```

The request sends a country and a year, nothing else. No learner, school, account or health data ever leaves the application through this call.

A successful response, abridged:

```json
{
  "meta": { "code": 200 },
  "response": {
    "holidays": [
      {
        "name": "Bonifacio Day",
        "date": { "iso": "2026-11-30" },
        "type": ["National holiday"],
        "primary_type": "Regular Holiday",
        "urlid": "philippines/bonifacio-day",
        "locations": "All"
      }
    ]
  }
}
```

## What is kept, and what is dropped

`CalendarificClient::interpret()` does not trust the reply as it arrives. An entry is kept only when **all** of these hold:

| Rule | Example it drops |
|---|---|
| It has a name and a real date (`date.iso`, checked with `checkdate`). | `2026-02-30` |
| The date falls in the year that was requested. | Next year's New Year's Day listed in this year's reply |
| It is a national holiday: regular or special non-working. | March Equinox (a season), observances |
| It is not a *special working day*: classes go on that day. | First Philippine Republic Day |
| It applies nationwide (`locations` = `All`), or names the configured `CALENDARIFIC_LOCATION`. | Day of Arafah (BARMM) |

Duplicate entries (Calendarific lists Black Saturday 2026 twice) are merged into one. HTML tags are stripped from names, and names are capped at 160 characters.

**A reply with no usable holiday is a failure, not an empty year.** A sync replaces the year's holidays with what the API returned, so a blank or malformed reply must never wipe the holidays already saved.

## Error handling

The client retries only failures that might clear on their own. Every request counts against the monthly quota, including failed ones, so a refusal is not asked again.

| Situation | Retried? | What the System Admin sees | Effect on saved holidays |
|---|---|---|---|
| No API key set | Nothing sent | "Holiday sync is not configured. Set CALENDARIFIC_API_KEY." | Unchanged |
| Network failure or timeout | Yes, 3 attempts | "Calendarific could not be reached…" | Unchanged |
| 401: key refused | No | "Calendarific refused the API key…" | Unchanged |
| 403: plan expired | No | "The Calendarific subscription has expired…" | Unchanged |
| 429: monthly limit reached | No | "The monthly Calendarific request limit has been reached…" | Unchanged |
| 500/502/503/504 | Yes, 3 attempts | "Calendarific is unavailable at the moment…" | Unchanged |
| An error reported inside an HTTP 200 (`meta.code` ≠ 200) | No | Same as the status it reports | Unchanged |
| Malformed body, or no usable holiday | No | "…returned a response that could not be read. Nothing was changed." | Unchanged |
| Success | — | "Public holidays synced from Calendarific — 2026: 18 holidays · 2027: 19 holidays." | Year replaced in one transaction |

The two years of a school year are synced independently, so a failure in one does not stop the other.

## Security

- **The key stays secret.** It is read only through `config('services.calendarific.key')`, never `env()` outside `config/`. It is never committed, and `phpunit.xml` blanks it so no test can reach the provider.
- **The key is redacted from logs.** Calendarific accepts the key only in the query string, and a transport error's message includes the full URL. Every failure is logged with the key replaced by `[redacted]`, and the admin sees a plain sentence instead of the raw exception.
- **No personal data is sent.** The request carries a country code and a year.
- **The reply is validated.** See the table above: dates are checked, the year is pinned, types and regions are filtered, and tags are stripped.
- **Only the System Admin can act.** Every route checks `active_role === 'system_admin'`. `RestrictSchoolHeadWrites` refuses all three routes for the School Head, and every route has a name so that middleware can match it.
- **Abuse is limited.** The Sync button is limited to 6 syncs an hour (`RateLimiter`), so pressing it repeatedly cannot burn the monthly quota.
- **Manual entries are checked.** The school must exist; the type must be *Class suspension* or *School break* (a holiday can only come from the API); the end date cannot be before the start date; an entry can cover at most 31 days; and it cannot overlap one already on file. **An entry cannot cover a day the school recorded attendance on**, because a day the school fed is not a day it was closed.
- **Every change is audited.** A sync writes one `audit_logs` entry with its counts. A manual entry and its removal go through the `Auditable` trait. The name of whoever entered a row is encrypted at rest (`EncryptedString`).
- **Schools are kept apart.** A suspension belongs to one school (`institution_id`); only national holidays have no school.

## Using it

**System Admin, Control Center → School Calendar**
- *Public Holidays*: shows how many holidays are saved for each calendar year and when they were last synced. **Sync Holidays** reads both years of the current school year.
- *Class Suspension or School Break*: choose the school, the type, the date range and the reason, then **Add to Calendar**.
- The table lists every closed day in the current school year. Holidays are labelled *Calendarific* and *All schools*. Hand-entered rows show who entered them and have a **Remove** button. A synced holiday has no Remove button, because the next sync would bring it back.

**Feeding Coordinator**
- The Dashboard's feeding-day counter and cycle bar skip closed days, including the bar's own recount at midnight.
- On the Attendance tab, a closed day shows **No session — holiday** (or *class suspension* or *school break*) with its name. The date picker locks it, the Previous/Next arrows step over it, and the Calendar view draws it hatched with a *Holiday*, *Suspended* or *Break* tag.
- Saving attendance for a closed day is refused, with the reason given.

**School Head**: the "Today's feeding session has not been recorded" warning no longer appears on a weekend, a holiday, a suspension or a break. It is also judged on the school's clock rather than the server's UTC date.

## Command and schedule

```bash
php artisan holidays:sync              # both years of the current school year
php artisan holidays:sync 2027         # one calendar year
php artisan holidays:sync 2026 2027    # several
```

The command exits with code 1 if any year fails. `routes/console.php` schedules it monthly (1st of the month, 03:00), because a proclamation can add or move a special non-working day mid-year. As with the retention purge, nothing on the deployment runs `schedule:run` yet, so until a cron service does, the Sync button is how the calendar gets updated.

## Testing

`tests/Feature/SchoolCalendarTest.php` has 28 tests. None of them reach Calendarific: `Http::fake()` stands in for it with a reply shaped like the documented one and the kinds of entry the Philippine list actually carries.

```bash
php artisan test --filter SchoolCalendarTest
```

What the tests cover:
- **Client:** which entries are kept and dropped; the exact query sent; 401 (as the HTTP status and inside a 200), 429 (not retried), 503 (retried three times), unreadable or empty replies, and connection failures, each leaving saved holidays untouched; the key absent from the logged error; nothing sent with no key.
- **Sync:** a re-sync replaces only the API's rows; one audit entry per sync.
- **Cycle:** a weekday holiday is taken off the count and a weekend one is not; the end date moves one feeding day per closed weekday; a suspension closes one school only.
- **Screens:** recording on a holiday is refused; the sheet names the holiday and offers no Record button; the picker receives the closed dates; the calendar draws the day locked; the head's warning disappears on a holiday.
- **Admin:** the role guard (including the School Head's 403); the rate limit; validation; refusal over a day with recorded attendance; removal rules; the Control Center renders the section.
- **Command:** a valid year succeeds, a malformed one fails, and a missing key fails loudly.

## Known limits

- A school that holds class on a special non-working day cannot exempt itself from that national holiday. Recording attendance that day is refused.
- Local holidays depend on what Calendarific lists for the configured `CALENDARIFIC_LOCATION`. Davao City's own holidays may need to be entered by hand as breaks.
- A synced holiday cannot be removed from the app, because the next sync would restore it.
