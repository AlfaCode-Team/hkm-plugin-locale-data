# Changelog

All notable changes to `hkm-plugin-locale-data` are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: countries, continents, currencies and languages, localized
  and DERIVED from the system's own CLDR through `ext-intl`.** Solves
  `locale.data`, requires no other module, and has no database table and no
  migration.

- **Derived rather than shipped, and the reason is verifiability.** A table of
  250 countries, 153 currencies and 181 languages is ~600 lines of literal data
  whose problem is not its size: it CANNOT BE VERIFIED. Every value would be
  typed from memory, and a mistake in reference data is invisible — nothing
  fails, the wrong ISO-3 code is simply sent to a correspondent bank that
  rejects the payment a week later. It also could not be localized (250
  countries × ten locales is 2,500 strings translated by nobody, when the
  operating system already has them in ninety) and it would go stale silently.

- **Exactly two things are shipped.** The EU member list, because CLDR
  genuinely does not have it — `territoryContainment` has no `EU` key, which was
  CHECKED rather than assumed — and it decides real behaviour: VAT treatment,
  GDPR applicability, Strong Customer Authentication. And the seven-continent
  assembly, which is a DEFINITION rather than data: CLDR has five UN M49
  macro-regions and no Antarctica, so `NA` is assembled from `021 + 013 + 029`,
  `SA` from `005`, and `AQ` is the single place this plugin overrides CLDR,
  which files Antarctica under Outlying Oceania.

- **Transcontinental countries follow CLDR deliberately.** Russia is `EU`,
  Turkey and Cyprus are `AS`. Each has an argument against it and none has a
  right answer, which is the reason to defer rather than re-litigate — a
  hand-maintained exception list would be this plugin's opinion presented as
  reference data, and it would drift from the names and codes that come from the
  same source. The placements are pinned by tests so a CLDR change is NOTICED.

- **The cost is stated rather than hidden: the data is only as current as the
  system's ICU.** Two servers on different ICU versions can return different
  country NAMES for the same code — codes never move, but "Turkey" became
  "Türkiye" in CLDR 42. So `describe()` reports the ICU version, the cache key
  CARRIES it so an upgrade invalidates rather than serving last week's names for
  a day, and `locale:data` exists to be run on the box and say what that ICU
  provides.

- **`ext-intl` is required, with no fallback path**, because the alternative is
  the unverifiable table above. Declared in `composer.json`, and checked at the
  catalogue's construction so a deployment without it fails when the module is
  wired rather than on the first request that happens to list countries.

- **Lists are COLLATED, not sorted.** Alphabetical order is a property of the
  locale, not of the bytes: `É` is U+00C9, two bytes starting `0xC3`, above
  every ASCII letter — so a byte sort exiles every French country name beginning
  with É below Zimbabwe. That is the difference between a usable picker and one
  with a chunk of the names heaped at the bottom. An ICU `Collator` per locale,
  falling back to `strcmp` only if ICU cannot produce one at all.

  Currencies are the one list sorted by CODE rather than name, because a
  currency list is read and searched by its code — somebody looking for USD
  scans for "USD", not for "US Dollar" filed under U.

- **`decimalDigits` comes from ICU, because it is not 2 everywhere.** JPY, UGX,
  KRW, RWF, XAF and XOF have 0; KWD, BHD, JOD, OMR and TND have 3. Hard-coding
  100 is a factor-of-100 billing error in both directions: a 1,000 JPY charge
  billed as 10 JPY, or 1.000 KWD billed as 1,000 KWD. `minorUnitsPerMajor()`
  gives the divisor.

- **A currency symbol that IS the code is correct, not missing.** ICU returns
  `CDF` for the Congolese franc and `$` for USD — CLDR saying there is no
  distinct narrow symbol in this locale and the code is what should be
  displayed. The symbol is also locale-dependent (`$` in `en`, `$US` in `fr`),
  which is why it is resolved per requested locale rather than stored once.

- **Numeric codes are STRINGS.** `004` is Afghanistan, and the zeros are part of
  the code — every standard, form and downstream system writes them. Typed
  `string` so nothing downstream can canonicalise them away: `'004'` used as an
  array key stays `'004'`, while `4` becomes a different key.

- **Both halves of the country filter are needed**, and there is a test for
  each. `idValidity.region.regular` includes CLDR's exceptionally-reserved
  codes — AC, CP, DG, EA, IC, TA, CQ — which are real CLDR regions and not ISO
  3166-1 countries; `codeMappings` alone carries 303 entries including
  user-assigned codes such as `AA`. Requiring both leaves exactly 250.

- **Current currencies only.** The list comes from
  `idValidity.currency.regular` and not from `codeMappingsCurrency`, which has
  183 entries including withdrawn codes — a currency picker offering the
  Deutsche Mark or the Netherlands Antillean guilder is a picker with a bug in
  it.

- **Languages are ISO 639-1 and carry their NATIVE name.** CLDR's list is ISO
  639-3 with over seven thousand entries, which is a picker nobody can use;
  filtering to two letters IS ISO 639-1. The native name is what a language
  picker must show, because a user who cannot read the current interface
  language cannot read "German" either.

- **No locale negotiation, no geolocation, no dial codes, no routes.**
  Negotiation belongs to `i18n.translation`, which decides WHICH locale a
  visitor gets while this plugin says what things are called in it. Geolocation
  would mean bundling a 60 MB MaxMind database with an attribution licence and
  periodic updates inside a composer package. Dial codes have no authoritative
  offline source since ICU dropped `telephoneCodeData`, and ~250 hand-written
  ones would be reference data that LOOKS authoritative while being wrong in a
  dozen places — and a wrong dial code means a user cannot be reached. Routes
  would impose a response shape and a caching policy on every consumer to save
  three lines of controller.

- **`locale:data` exits non-zero on an incomplete ICU**, so it works as a deploy
  check on an image built with a stripped-down ICU. A continent with zero
  countries is called out by name, because that is the exact symptom of a broken
  containment walk.

### Fixed during development

- **103 of 250 countries had no continent, and nothing threw.** PHP
  canonicalises numeric-string array KEYS to integers, so iterating
  `territoryContainment` yields a mix: `'002'` stays a string because a leading
  zero is not a canonical int, while `'150'` becomes `int 150`. The containment
  walk guarded with `is_string($group)`, which silently dropped every M49 group
  without a leading zero — Europe (150), Asia (142) and all their sub-regions.
  Every missing country was European or Asian. Two tests now pin it; reinstating
  the guard produces 44 errors.

- **Two `str_pad()` calls were dead code with a comment claiming otherwise.**
  Having found the key-canonicalisation trap, the same padding was applied to
  the numeric country code and the currency numeric "for the same reason" —
  and revert checks showed BOTH passing. Bundle VALUES keep their leading
  zeros: `codeMappings` gives Albania's numeric code as the string `'008'`. The
  scope of the trap is keys only. Both calls are gone, and the class docblock
  now says where the boundary is, because a comment that claims to fix a bug it
  does not is how the next reader learns the wrong lesson.

- **The `und-` prefix in `regionName()` is load-bearing.**
  `Locale::getDisplayRegion('US')` parses its argument as a LOCALE, and `'US'`
  as a locale is the language `us` with no region — so it returns an empty
  string and every country name would be blank.

### Notes

- Pairs with `i18n.translation`: that plugin decides the locale, this one
  renders the data in it. Neither depends on the other.
- Bind a `CachePort`. Building a locale's lists costs a measured 5–17 ms, paid
  per request by any page with a country or currency picker. The driver must
  round-trip OBJECTS, because value objects are cached rather than their array
  form; every first-party adapter PHP-serializes, so this holds — but a
  JSON-only driver would hand back arrays and break.
- The EU list has **no automated check against reality** and cannot have one:
  there is no offline authority to compare against, which is the same reason the
  dial codes are absent. It is dated in its docblock and that is the whole of
  the guarantee.
- `m49()`'s zero-padding is **unpinned** and kept as belt and braces: PHP
  canonicalises `'150'` and `150` to the same array key on both the storing and
  the reading side, so removing it passes the suite. It stays so that a code
  taken out of the map and used as a string is unambiguous.
