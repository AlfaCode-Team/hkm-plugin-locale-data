<?php

declare(strict_types=1);

namespace Plugins\LocaleData\API\Contracts;

use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Domain\Country;
use Plugins\LocaleData\Domain\Currency;
use Plugins\LocaleData\Domain\Language;

/**
 * Countries, continents, currencies and languages — localized, and derived
 * from the system's own CLDR rather than from a table in this repository.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS FOR, AND WHAT IT IS NOT
 * ────────────────────────────────────────────────────────────────────────────
 *
 * It answers "what countries exist, what are they called in French, which
 * currency does each use, how many decimal places does that currency have" —
 * the data behind a country picker, an address form, a currency selector, a
 * VAT decision.
 *
 * It is NOT locale NEGOTIATION. Deciding which language to show a visitor is
 * `i18n.translation`'s job: it reads `Accept-Language` against `APP_LOCALES`
 * and sets the active locale. This plugin takes a locale as an argument and
 * tells you what things are called in it. The two pair up — the i18n plugin
 * decides `fr`, this one renders the country list in French — and neither
 * does the other's work.
 *
 * It is also NOT geolocation. There is no IP-to-country lookup here, and that
 * is deliberate rather than unfinished: see the README.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE LOCALE ARGUMENT
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `null` means the configured default (`LOCALE_DATA_DEFAULT`, itself defaulting
 * to `APP_LOCALE` and then `en`). An unknown or malformed locale falls back to
 * the default rather than throwing — a bad `Accept-Language` from a client must
 * not 500 a country list — and `describe()` is where an operator can see which
 * locale is actually in force.
 */
interface LocaleDataContract
{
    /**
     * Every ISO 3166-1 country, sorted by localized name.
     *
     * Sorted with ICU's `Collator` and not `sort()`, because alphabetical
     * order is locale-dependent: `Österreich` sorts under O in German and
     * after Z in Swedish, and a byte-wise sort puts every accented name after
     * every unaccented one in any locale.
     *
     * @return list<Country>
     */
    public function countries(?string $locale = null): array;

    /** @return Country|null null for an unknown code, never an exception */
    public function country(string $alpha2, ?string $locale = null): ?Country;

    /**
     * The EU member states, sorted by localized name.
     *
     * Means exactly "member state of the European Union" — not the EEA, not
     * Schengen, not the Eurozone. See {@see \Plugins\LocaleData\Domain\EuropeanUnion}.
     *
     * @return list<Country>
     */
    public function euCountries(?string $locale = null): array;

    /**
     * Countries on one continent, sorted by localized name.
     *
     * @param string $continent one of {@see Continent}'s seven codes
     * @return list<Country> empty for an unknown continent code
     */
    public function countriesIn(string $continent, ?string $locale = null): array;

    /** @return list<Continent> the seven, sorted by localized name */
    public function continents(?string $locale = null): array;

    /** @return list<Currency> sorted by code, which is how a currency list is read */
    public function currencies(?string $locale = null): array;

    /** @return Currency|null null for an unknown code */
    public function currency(string $code, ?string $locale = null): ?Currency;

    /** @return list<Language> sorted by localized name */
    public function languages(?string $locale = null): array;

    /** @return Language|null null for an unknown code */
    public function language(string $code, ?string $locale = null): ?Language;

    /**
     * Provenance and counts — including the ICU version, because the data is
     * exactly as current as that. Never a credential.
     */
    public function describe(): string;
}
