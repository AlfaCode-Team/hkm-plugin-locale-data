# hkm-plugin-locale-data

Countries, continents, currencies and languages — localized, and **derived from
the system's own CLDR** rather than from a table in this repository.

```php
$locale->countries('fr');            // 250, collated: Allemagne, …, Égypte, …, Zimbabwe
$locale->country('CD')->alpha3;      // 'COD'
$locale->country('CD')->numeric;     // '180'  — a STRING, zeros intact
$locale->currency('KWD')->decimalDigits;   // 3, not 2
$locale->euCountries();              // the 27
$locale->countriesIn('AF');          // 60
$locale->language('sw')->nativeName; // 'Kiswahili'
```

| | |
|---|---|
| `solves` | `locale.data` |
| `requires` | nothing — and **no database table, no migration** |
| `exposes` | `LocaleDataContract` |
| Needs | **`ext-intl`** (not optional — see below) |
| Kernel floor | `^1.3.3` |
| Commands | `locale:data` |

---

## Why derive instead of shipping a table

The obvious implementation is a PHP array of 250 countries, 153 currencies and
181 languages. That is ~600 lines of literal data, and the problem with it is
not its size:

**It cannot be verified.** Every value would be typed from memory, and a mistake
in reference data is *invisible* — nothing fails, the wrong ISO-3 code is simply
sent to a correspondent bank that rejects the payment a week later. Shipping
unverifiable data that **looks** authoritative is worse than shipping none.

**It cannot be localized.** Names for 250 countries in even ten locales is 2,500
strings, translated by nobody. The operating system already has them in ninety.

**It goes stale silently**, and it is the project that has to notice.

So everything CLDR carries is read from CLDR through `ext-intl`:

| | from |
|---|---|
| alpha-2 / alpha-3 / numeric codes | `supplementalData → codeMappings` |
| the country, continent and language set | `supplementalData → idValidity` |
| continent placement | `supplementalData → territoryContainment` (UN M49) |
| country, language and currency names | `Locale::getDisplay*`, `ICUDATA-curr` |
| currency symbols and **decimal digits** | `NumberFormatter` |

### What *is* shipped, and why it has to be

Exactly two things.

**The EU member list** (`Domain/EuropeanUnion.php`), because CLDR genuinely does
not have it — `territoryContainment` has no `EU` key, which was *checked, not
assumed*. It is a defensible list to hand-write: 27 states, one change in the
last decade, and it decides real behaviour (VAT, GDPR, SCA). It is dated in its
docblock, and it is the one piece of data here that can go stale without the
operating system noticing.

**The seven-continent assembly** (`Domain/Continent.php`), which is a
*definition* rather than data. CLDR has five UN M49 macro-regions and no
Antarctica, so `NA` is assembled from `021 + 013 + 029`, `SA` from `005`, and
`AQ` is the single place this plugin overrides CLDR — which files Antarctica
under *Outlying Oceania*.

Transcontinental countries follow CLDR deliberately: Russia is `EU`, Turkey and
Cyprus are `AS`. Each has an argument against it and none has a right answer,
which is the reason to defer rather than to re-litigate. A hand-maintained
exception list would be this plugin's opinion presented as reference data.

### The cost, stated plainly

**The data is only as current as the system's ICU.** Two servers on different
ICU versions can return different country *names* for the same code — codes
never move, but "Turkey" became "Türkiye" in CLDR 42. So:

- `describe()` reports the ICU version;
- the cache key **carries** it, so an ICU upgrade invalidates rather than serving
  last week's names for a day;
- `locale:data` exists to be run *on the box* and say what that ICU provides.

### `ext-intl` is required

Not optional, and there is no fallback path — the alternative is the
unverifiable table above. It is declared in `composer.json`, and
`IcuLocaleCatalogue` checks it at construction so a deployment without it fails
when the module is wired rather than on the first request that happens to list
countries.

---

## What this is not

**Not locale negotiation.** Deciding *which* language a visitor gets is
`i18n.translation`'s job — it negotiates `Accept-Language` against `APP_LOCALES`
and sets the active locale. This plugin takes a locale as an argument and says
what things are *called* in it. They pair up; neither does the other's work.

**Not geolocation.** Appwrite's `/locale` endpoint resolves a visitor's country
from their IP using a bundled MaxMind GeoLite2 database. That is a 60 MB binary
with a licence requiring attribution and periodic updates, and shipping one
inside a composer package would mean every install carries it and every install
serves whatever snapshot was vendored. An application that needs IP geolocation
should bind its own provider.

**No dial codes**, and this one is a considered omission rather than an
oversight. Appwrite has `/locale/countries/phones`. ICU dropped
`telephoneCodeData`, so there is no authoritative offline source here — and ~250
hand-written dial codes would be reference data that *looks* authoritative while
being wrong in a dozen places. A wrong dial code means a user cannot be reached.
`libphonenumber` is the right source; an application that needs them should use
it.

**No HTTP routes.** Reference data is read by a project's own pages and APIs,
which shape it to their own response envelope and cache it at their own edge.
Shipping `GET /locale/countries` would impose a response shape and a caching
policy on every consumer to save them three lines of controller.

---

## Two things worth knowing about the data

### `decimalDigits` is not 2 everywhere

Money is stored as integer minor units in this framework, and the number of
minor units per major unit is **not** two for every currency:

```
JPY UGX KRW VND RWF XAF XOF …   0
most of the world               2
KWD BHD JOD OMR TND …           3
```

Hard-coding 100 is a factor-of-100 error in both directions: a 1,000 JPY charge
billed as 10 JPY, or a 1.000 KWD charge billed as 1,000 KWD. `minorUnitsPerMajor()`
gives the divisor, and the value comes from ICU rather than from a guess.

### Lists are collated, not sorted

Alphabetical order is a property of the **locale**, not of the bytes. `É` is
U+00C9 — two bytes starting `0xC3`, above every ASCII letter — so a byte sort
exiles every French country name beginning with É (`Égypte`, `Équateur`,
`Éthiopie`, `États-Unis`, `Émirats arabes unis`) *below Zimbabwe*.

That is not a refinement; it is the difference between a usable picker and one
where a chunk of the names are in a heap at the bottom. The service uses an ICU
`Collator` per locale and falls back to `strcmp` only if ICU cannot produce one
at all.

Currencies are the one list sorted by **code** rather than by name, because a
currency list is read and searched by its code — somebody looking for USD scans
for "USD", not for "US Dollar" filed under U.

---

## Configuration

| Key | Default | |
|---|---|---|
| `LOCALE_DATA_DEFAULT` | `en` | falls through to `APP_LOCALE`, then `en` |
| `LOCALE_DATA_CACHE_TTL` | `86400` | `0` turns cross-request caching off |

Building a locale's lists costs a **measured 5–17 ms** (250 `getDisplayRegion`
calls, 153 currency lookups, a collation sort — the currency half dominates),
paid per request by any page with a country or currency picker on it. So bind a
`CachePort`: without one the service still memoizes per request, which is enough
for one page but not across them.

> The cache driver must round-trip **objects**, because value objects are cached
> rather than their array form. Every first-party adapter does — they
> PHP-serialize anything non-numeric. A JSON-only driver would hand back arrays
> and break, which is why this is stated rather than assumed.

A malformed `LOCALE_DATA_DEFAULT` falls back to `en` rather than being passed to
ICU. ICU is tolerant — it would quietly resolve almost anything to its root
locale and return English — so the failure would otherwise be a country list in
the wrong language with nothing reporting why.

## `locale:data`

```sh
hkm cli locale:data                  # counts, plus countries per continent
hkm cli locale:data -l fr            # the same, rendered in French
hkm cli locale:data --countries      # every row
```

It exits **non-zero** when the catalogue is obviously incomplete, so it works as
a deploy check on an image built with a stripped-down ICU — the failure mode that
otherwise shows up as a half-empty country picker. A continent with zero
countries is called out by name, because that is the exact symptom of a broken
containment walk.

---

## The bug this plugin was built around

**PHP canonicalises numeric-string array keys to integers.** Iterating a
`ResourceBundle` therefore yields a mix of key types — measured, not assumed:

```
'002'  →  string   (a leading zero is not a canonical int)
'150'  →  int 150
```

The first version of the containment walk guarded with `is_string($group)`,
which silently dropped every M49 group without a leading zero — Europe (150),
Asia (142) and all their sub-regions. **103 of 250 countries came back with no
continent, every one of them European or Asian, and nothing threw.**

Two tests pin it: *every country resolves to a continent*, and *no continent is
empty*. Reinstating the guard produces 44 errors.

The scope is **keys only**, and finding that out cost a second round. Bundle
*values* keep their leading zeros — `codeMappings` gives Albania's numeric code
as the string `'008'`. Two `str_pad()` calls had been written "for the same
reason", and revert checks showed both passing: they were dead code carrying a
comment that claimed to fix a bug they did not. They are gone.

---

## Verified

- **94 tests, 2,739 assertions**, green. `ground check --strict`: clean.
- **All 250 countries resolve to a continent**, no continent is empty, and the
  continents partition the country set exactly.
- Codes checked against the standard for eleven countries, including the
  leading-zero cases (`AL` → `008`, `AF` → `004`, `DZ` → `012`).
- CLDR's exceptionally-reserved codes (`AC CP DG EA IC TA CQ`) and user-assigned
  codes (`AA`) are excluded — both halves of the filter are needed and there is a
  test for each.
- `decimalDigits` checked against ICU for fifteen currencies across all three
  classes (0, 2, 3).
- Withdrawn currencies (`DEM FRF ITL ANG ZWD`) are absent.
- Exactly the 27 EU member states are flagged; `GB` is not; `NO CH IS RS UA` are
  in Europe and not in the EU.
- The Antarctica override is pinned, as are the transcontinental placements — so
  a CLDR change there is *noticed* rather than silently shipped.
- Localized names and symbols checked in `en` and `fr`; native language names
  checked (`de` → `Deutsch`, `sw` → `Kiswahili`).
- **Eleven revert checks**, nine of which failed. The two that passed are
  recorded above and the dead code they exposed was deleted.

## NOT verified

- **Only one ICU version has ever run this** (ICU 79.1, CLDR as shipped with it).
  Count assertions are deliberately ranges and name assertions avoid anything
  CLDR has already renamed, but no older ICU has been tested — and the plugin's
  whole premise is that the data comes from whatever ICU is installed.
- **The command has never been run.** `locale:data` is covered by reading only;
  its table rendering and its non-zero exit on an incomplete ICU have not
  executed.
- **No stripped-down ICU has been tested.** The `bundleMissing` path and the
  command's "this ICU is missing data" branch are reasoned about, not exercised —
  producing an ICU without its supplemental bundle is not something this suite
  can arrange.
- **The EU list has no automated check against reality.** It cannot have one:
  there is no offline authority to compare against, which is the same reason the
  dial codes are absent. It is dated in its docblock and that is the whole of the
  guarantee.
- **`m49()`'s zero-padding is unpinned** and documented as belt-and-braces: PHP
  canonicalises `'150'` and `150` to the same array key on both the storing and
  the reading side, so removing it passes the suite.
- **No HTTP request has reached this plugin**, because it has no routes.

---

## Licence

MIT. See `LICENSE`.
