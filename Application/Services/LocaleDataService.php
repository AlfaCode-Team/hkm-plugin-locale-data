<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\LocaleData\API\Contracts\LocaleDataContract;
use Plugins\LocaleData\Application\Ports\LocaleCatalogue;
use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Domain\Country;
use Plugins\LocaleData\Domain\Currency;
use Plugins\LocaleData\Domain\Language;
use Plugins\LocaleData\Infrastructure\Config\LocaleDataConfig;

/**
 * Ordering, lookup, locale resolution and caching over a {@see LocaleCatalogue}.
 *
 * The catalogue produces unordered data; everything a caller actually wants
 * from a reference list — sorted by the right collation, indexed by code,
 * filtered by continent — is here, so a second catalogue implementation cannot
 * disagree about any of it.
 */
final class LocaleDataService implements LocaleDataContract
{
    /**
     * Per-instance memo, keyed by `kind:locale`.
     *
     * An INSTANCE property and never a static: this object is request-scoped,
     * and a static would hold one request's locale for the life of an
     * OpenSwoole worker — so the second request in another language would be
     * served the first one's country names.
     *
     * @var array<string, list<Country>|list<Continent>|list<Currency>|list<Language>>
     */
    private array $memo = [];

    public function __construct(
        private readonly LocaleCatalogue $catalogue,
        private readonly LocaleDataConfig $config,
        /**
         * Optional, and worth binding: building the lists costs a MEASURED
         * 5–17 ms per locale (250 `getDisplayRegion` calls, 153 currency
         * lookups, and a collation sort — the currency half dominates), paid
         * per request by every page with a country or currency picker on it.
         *
         * The driver must round-trip OBJECTS, because value objects are cached
         * rather than their array form. Every first-party adapter does: they
         * PHP-serialize anything non-numeric. A hypothetical JSON-only driver
         * would return arrays and break — which is why this is stated rather
         * than assumed.
         */
        private readonly ?CachePort $cache = null,
    ) {}

    // ── Countries ────────────────────────────────────────────────────────────

    /** @return list<Country> */
    public function countries(?string $locale = null): array
    {
        $locale = $this->resolve($locale);

        /** @var list<Country> */
        return $this->remember('countries', $locale, function () use ($locale): array {
            $countries = $this->catalogue->countries($locale);

            $this->sortByName($countries, $locale);

            return $countries;
        });
    }

    public function country(string $alpha2, ?string $locale = null): ?Country
    {
        $alpha2 = strtoupper(trim($alpha2));

        foreach ($this->countries($locale) as $country) {
            if ($country->alpha2 === $alpha2) {
                return $country;
            }
        }

        // A linear scan over an already-built, already-cached list of 250 —
        // cheaper than maintaining a second index, and this is not a hot path.
        // Null and not an exception: an unknown country code in a form is a
        // caller's input, and the caller decides what to do about it.
        return null;
    }

    /** @return list<Country> */
    public function euCountries(?string $locale = null): array
    {
        // Filtered from the sorted list rather than sorted again, so EU
        // countries appear in the same order they do in the full list.
        return array_values(array_filter(
            $this->countries($locale),
            static fn (Country $c): bool => $c->isEu,
        ));
    }

    /** @return list<Country> */
    public function countriesIn(string $continent, ?string $locale = null): array
    {
        $continent = strtoupper(trim($continent));

        return array_values(array_filter(
            $this->countries($locale),
            static fn (Country $c): bool => $c->continent === $continent,
        ));
    }

    // ── Continents, currencies, languages ────────────────────────────────────

    /** @return list<Continent> */
    public function continents(?string $locale = null): array
    {
        $locale = $this->resolve($locale);

        /** @var list<Continent> */
        return $this->remember('continents', $locale, function () use ($locale): array {
            $continents = $this->catalogue->continents($locale);

            $this->sortByName($continents, $locale);

            return $continents;
        });
    }

    /** @return list<Currency> */
    public function currencies(?string $locale = null): array
    {
        $locale = $this->resolve($locale);

        /** @var list<Currency> */
        return $this->remember('currencies', $locale, function () use ($locale): array {
            $currencies = $this->catalogue->currencies($locale);

            // By CODE and not by name, which is the one list here that is not
            // sorted alphabetically by label — because a currency list is read
            // and searched by its code. Somebody looking for USD scans for
            // "USD", not for "United States Dollar" filed under U.
            usort($currencies, static fn (Currency $a, Currency $b): int => $a->code <=> $b->code);

            return $currencies;
        });
    }

    public function currency(string $code, ?string $locale = null): ?Currency
    {
        $code = strtoupper(trim($code));

        foreach ($this->currencies($locale) as $currency) {
            if ($currency->code === $code) {
                return $currency;
            }
        }

        return null;
    }

    /** @return list<Language> */
    public function languages(?string $locale = null): array
    {
        $locale = $this->resolve($locale);

        /** @var list<Language> */
        return $this->remember('languages', $locale, function () use ($locale): array {
            $languages = $this->catalogue->languages($locale);

            $this->sortByName($languages, $locale);

            return $languages;
        });
    }

    public function language(string $code, ?string $locale = null): ?Language
    {
        $code = strtolower(trim($code));

        foreach ($this->languages($locale) as $language) {
            if ($language->code === $code) {
                return $language;
            }
        }

        return null;
    }

    public function describe(): string
    {
        $parts = [];

        foreach ([...$this->catalogue->describe(), ...$this->config->describe()] as $key => $value) {
            $parts[] = $key . '=' . (\is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        $parts[] = 'cache=' . ($this->cache === null ? 'none' : 'bound');

        return implode(' ', $parts);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function resolve(?string $locale): string
    {
        $locale = trim((string) $locale);

        return $locale !== '' ? $locale : $this->config->defaultLocale;
    }

    /**
     * Sort a list of value objects by their localized `name`.
     *
     * ──────────────────────────────────────────────────────────────────────
     * A `Collator`, NOT `sort()` OR `strcmp()`
     * ──────────────────────────────────────────────────────────────────────
     *
     * Alphabetical order is a property of the LOCALE, not of the bytes:
     *
     *   `Österreich` sorts with O in German and after Z in Swedish.
     *   `Côte d'Ivoire` sorts under C, but a byte-wise sort puts every
     *   accented name after every unaccented one — so in a French country
     *   list, `Côte d'Ivoire`, `Égypte` and `Éthiopie` all land at the end,
     *   below Zimbabwe.
     *
     * That last one is the whole reason this method exists: it is not a
     * refinement, it is the difference between a usable picker and one where
     * a third of the French names are in a heap at the bottom.
     *
     * @param list<Country>|list<Continent>|list<Currency>|list<Language> $items
     */
    private function sortByName(array &$items, string $locale): void
    {
        $collator = \collator_create($locale) ?: \collator_create('en');

        usort($items, static function (object $a, object $b) use ($collator): int {
            if ($collator === null) {
                // No collator at all — an ICU without the locale AND without
                // `en`, which should not happen. Byte order is wrong but
                // deterministic, which beats leaving the list unordered.
                return strcmp($a->name, $b->name);
            }

            // compare() returns false on error, and false <=> an int is a
            // nonsense comparison that makes usort's result arbitrary.
            $result = $collator->compare($a->name, $b->name);

            return $result === false ? strcmp($a->name, $b->name) : $result;
        });
    }

    /**
     * Memoize per instance, and in the CachePort when one is bound.
     *
     * @template T of list<object>
     * @param \Closure(): T $build
     * @return T
     */
    private function remember(string $kind, string $locale, \Closure $build): array
    {
        $memoKey = $kind . ':' . $locale;

        if (isset($this->memo[$memoKey])) {
            /** @var T */
            return $this->memo[$memoKey];
        }

        if ($this->cache === null || $this->config->cacheTtl === 0) {
            /** @var T */
            return $this->memo[$memoKey] = $build();
        }

        // THE CACHE KEY CARRIES THE ICU VERSION. Without it, a server upgraded
        // to a new ICU keeps serving the previous one's names out of Redis
        // until the TTL runs out — and with a day-long TTL on data that only
        // moves when ICU moves, that is precisely the moment the cache is
        // wrong and nothing says so.
        $key = 'locale_data:' . INTL_ICU_VERSION . ':' . $memoKey;

        /** @var T */
        $value = $this->cache->remember($key, $this->config->cacheTtl, $build);

        /** @var T */
        return $this->memo[$memoKey] = $value;
    }
}
