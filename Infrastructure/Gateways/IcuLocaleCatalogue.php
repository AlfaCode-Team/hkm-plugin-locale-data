<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Infrastructure\Gateways;

use Plugins\LocaleData\Application\Ports\LocaleCatalogue;
use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Domain\Country;
use Plugins\LocaleData\Domain\Currency;
use Plugins\LocaleData\Domain\EuropeanUnion;
use Plugins\LocaleData\Domain\Language;
use Plugins\LocaleData\Infrastructure\Exceptions\LocaleDataException;

/**
 * The reference data, read out of the system's own CLDR through `ext-intl`.
 *
 * A GATEWAY, because `ext-intl` is the vendor library and this class talks to
 * nothing else — no database, no HTTP, no services.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * WHY DERIVE, RATHER THAN SHIP A TABLE
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The obvious implementation is a PHP array of 250 countries, 153 currencies
 * and 181 languages with their codes and names. That is roughly six hundred
 * lines of literal data, and the problem with it is not its size:
 *
 *   IT CANNOT BE VERIFIED. Every value would be typed from memory, and a
 *   mistake in reference data is invisible — nothing fails, the wrong ISO-3
 *   code is simply sent to a correspondent bank that rejects the payment a
 *   week later. Shipping unverifiable data that LOOKS authoritative is worse
 *   than shipping none.
 *
 *   IT CANNOT BE LOCALIZED. Names for 250 countries in even ten locales is
 *   2,500 strings, translated by nobody. The operating system already has them
 *   in ninety.
 *
 *   IT GOES STALE SILENTLY, and it is the project that has to notice.
 *
 * So everything that CLDR carries is read from CLDR, and the plugin ships
 * exactly two pieces of data of its own: the EU member list
 * ({@see EuropeanUnion}, which CLDR genuinely does not have — checked, not
 * assumed) and the seven-continent assembly ({@see Continent}, which is a
 * definition rather than data).
 *
 * The cost is honest and worth stating: THE DATA IS ONLY AS CURRENT AS THE
 * SYSTEM'S ICU. Two servers on different ICU versions can return different
 * country names for the same code — the codes themselves do not move, but
 * "Turkey" became "Türkiye" in CLDR 42. {@see describe()} reports the ICU
 * version for exactly that reason, and anything cached must key on it.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE TRAP THAT COST THIS CLASS ITS CONTINENT MAPPING — AND ITS EXACT SCOPE
 * ════════════════════════════════════════════════════════════════════════════
 *
 * PHP canonicalises numeric-string array KEYS to integers. Iterating a
 * `ResourceBundle` into an array therefore yields a mix of key types, and this
 * is measured rather than assumed:
 *
 *   `'002'` stays a STRING, because a leading zero is not a canonical int.
 *   `'150'` becomes `int 150`.
 *
 * The first version of {@see containment()} guarded with `is_string($group)`,
 * which silently dropped every group without a leading zero — which is to say
 * Europe (150), Asia (142) and all their sub-regions. The symptom was that 103
 * of 250 countries had no continent, and every one of the missing ones was
 * European or Asian. Nothing threw. A revert check reinstating that guard
 * produces 44 errors, so it is thoroughly pinned.
 *
 * THE SCOPE IS KEYS ONLY. Bundle VALUES come back as strings with their
 * leading zeros intact — `codeMappings` gives Albania's numeric code as the
 * string `'008'`, not as `int 8`. That was also measured, after two
 * `str_pad()` calls were written "for the same reason" and a revert check
 * showed both passing: they were dead code carrying a comment that claimed to
 * fix a bug they did not. They are gone. The cast to `(string)` on the key
 * side is the whole of the fix.
 */
final class IcuLocaleCatalogue implements LocaleCatalogue
{
    /** Keys in `territoryContainment` that are not M49 groups. */
    private const NOT_A_GROUP = [
        'containedGroupings' => true,
        'deprecated'         => true,
        'grouping'           => true,
    ];

    /** A guard on the containment walk, which must not loop on malformed data. */
    private const MAX_DEPTH = 12;

    /** @var array<string, array{string, string}>|null alpha2 => [numeric, alpha3] */
    private ?array $codeMappings = null;

    /** @var array<string, string>|null child M49/region => parent M49 group */
    private ?array $containment = null;

    /** @var array<string, string>|null currency alpha => numeric */
    private ?array $currencyNumerics = null;

    /** @var list<string>|null */
    private ?array $countryCodes = null;

    /** @var list<string>|null */
    private ?array $currencyCodes = null;

    /** @var list<string>|null */
    private ?array $languageCodes = null;

    public function __construct()
    {
        // Checked here rather than at a call site, so a deployment without the
        // extension fails when the module is wired and not on the first request
        // that happens to list countries. The message names the fix because
        // "Class ResourceBundle not found" does not.
        if (!\extension_loaded('intl')) {
            throw LocaleDataException::intlMissing();
        }
    }

    /** @return list<Country> */
    public function countries(string $locale): array
    {
        $mappings = $this->codeMappings();
        $out      = [];

        foreach ($this->countryCodes() as $alpha2) {
            [$numeric, $alpha3] = $mappings[$alpha2];

            $out[] = new Country(
                alpha2:    $alpha2,
                alpha3:    $alpha3,
                numeric:   $numeric,
                name:      $this->regionName($alpha2, $locale),
                continent: $this->continentOf($alpha2),
                isEu:      EuropeanUnion::includes($alpha2),
            );
        }

        return $out;
    }

    /** @return list<Continent> */
    public function continents(string $locale): array
    {
        $out = [];

        foreach (Continent::codes() as $code) {
            // The name comes from CLDR for the group that represents this
            // continent — so the label is translated by the same source as
            // every country name. Antarctica's "group" is the territory AQ,
            // which CLDR does carry.
            $out[] = new Continent($code, $this->regionName(Continent::M49_GROUP[$code], $locale));
        }

        return $out;
    }

    /** @return list<Currency> */
    public function currencies(string $locale): array
    {
        $numerics = $this->currencyNumerics();
        $out      = [];

        foreach ($this->currencyCodes() as $code) {
            $out[] = new Currency(
                code:          $code,
                numeric:       $numerics[$code] ?? '',
                symbol:        $this->currencySymbol($code, $locale),
                name:          $this->currencyName($code, $locale),
                decimalDigits: $this->currencyDigits($code),
            );
        }

        return $out;
    }

    /** @return list<Language> */
    public function languages(string $locale): array
    {
        $out = [];

        foreach ($this->languageCodes() as $code) {
            $out[] = new Language(
                code:       $code,
                name:       \Locale::getDisplayLanguage($code, $locale),
                // The language's name IN ITSELF, which is what a language
                // picker must show: a user who cannot read the current
                // interface language cannot read "German" either.
                nativeName: \Locale::getDisplayLanguage($code, $code),
            );
        }

        return $out;
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return [
            'source'      => 'icu',
            // The one value an operator needs: the data is exactly as current
            // as this, and two servers on different versions can disagree
            // about a country's NAME (never its codes).
            'icu'         => INTL_ICU_VERSION,
            'countries'   => \count($this->countryCodes()),
            'currencies'  => \count($this->currencyCodes()),
            'languages'   => \count($this->languageCodes()),
            'continents'  => \count(Continent::codes()),
            'eu_members'  => EuropeanUnion::count(),
        ];
    }

    // ── Country, currency and language code sets ─────────────────────────────

    /**
     * The ISO 3166-1 countries: CLDR's "regular" regions that also have an ISO
     * code mapping.
     *
     * Both halves are needed. `idValidity.region.regular` includes CLDR's
     * exceptionally-reserved codes — AC (Ascension), CP (Clipperton), DG
     * (Diego Garcia), EA (Ceuta & Melilla), IC (Canary Islands), TA (Tristan
     * da Cunha), CQ (Sark) — which are real CLDR regions and NOT ISO 3166-1
     * assigned countries. None of them has an entry in `codeMappings`, so
     * requiring one filters exactly those seven out and leaves 250.
     *
     * `codeMappings` alone is not enough either: it carries 303 entries
     * including user-assigned and deprecated codes such as `AA`.
     *
     * @return list<string>
     */
    private function countryCodes(): array
    {
        if ($this->countryCodes !== null) {
            return $this->countryCodes;
        }

        $mappings = $this->codeMappings();
        $codes    = [];

        foreach ($this->validIds('region', 'regular') as $code) {
            if (isset($mappings[$code])) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return $this->countryCodes = $codes;
    }

    /**
     * Current ISO 4217 currencies.
     *
     * `idValidity.currency.regular` rather than `codeMappingsCurrency`: the
     * latter has 183 entries including historical codes, and a currency picker
     * offering the Netherlands Antillean guilder is a picker with a bug in it.
     *
     * @return list<string>
     */
    private function currencyCodes(): array
    {
        if ($this->currencyCodes !== null) {
            return $this->currencyCodes;
        }

        $codes = $this->validIds('currency', 'regular');
        sort($codes);

        return $this->currencyCodes = $codes;
    }

    /**
     * ISO 639-1 languages: the two-letter subset of CLDR's valid languages.
     *
     * CLDR's list is ISO 639-3 and has over seven thousand entries. Filtering
     * to length two is exactly ISO 639-1, because that is what a two-letter
     * code IS — the ~180 languages that were assigned one.
     *
     * @return list<string>
     */
    private function languageCodes(): array
    {
        if ($this->languageCodes !== null) {
            return $this->languageCodes;
        }

        $codes = array_values(array_filter(
            $this->validIds('language', 'regular'),
            static fn (string $code): bool => \strlen($code) === 2,
        ));

        sort($codes);

        return $this->languageCodes = $codes;
    }

    // ── Reading the supplemental bundle ──────────────────────────────────────

    /**
     * `idValidity.<kind>.<status>`, with CLDR's range tokens expanded.
     *
     * @return list<string>
     */
    private function validIds(string $kind, string $status): array
    {
        $bundle = $this->supplemental()->get('idValidity');
        $group  = $bundle instanceof \ResourceBundle ? $bundle->get($kind) : null;
        $list   = $group instanceof \ResourceBundle ? $group->get($status) : null;

        if (!$list instanceof \ResourceBundle) {
            throw LocaleDataException::bundleMissing("idValidity.{$kind}.{$status}");
        }

        $out = [];

        foreach ($list as $token) {
            foreach (self::expandRange((string) $token) as $id) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Expand a CLDR range token.
     *
     * CLDR compresses consecutive codes: `AC~G` means AC AD AE AF AG, and
     * `013~5` means 013 014 015. The character before the tilde is the START
     * of the range on the LAST position; the one after it is the end.
     *
     * A token with no tilde is a single id, which is most of them.
     *
     * @return list<string>
     */
    private static function expandRange(string $token): array
    {
        $tilde = strpos($token, '~');

        if ($tilde === false || $tilde < 1 || $tilde + 1 >= \strlen($token)) {
            return [$token];
        }

        $prefix = substr($token, 0, $tilde - 1);
        $from   = \ord($token[$tilde - 1]);
        $to     = \ord($token[$tilde + 1]);

        if ($to < $from) {
            // Malformed rather than empty: returning [] would silently drop
            // ids, which is the failure mode this whole class is careful about.
            return [$token];
        }

        $out = [];

        for ($c = $from; $c <= $to; $c++) {
            $out[] = $prefix . \chr($c);
        }

        return $out;
    }

    /**
     * alpha-2 => [numeric, alpha-3].
     *
     * `codeMappings` rows are positional triples: [alpha2, numeric, alpha3].
     *
     * @return array<string, array{string, string}>
     */
    private function codeMappings(): array
    {
        if ($this->codeMappings !== null) {
            return $this->codeMappings;
        }

        $rows = $this->supplemental()->get('codeMappings');

        if (!$rows instanceof \ResourceBundle) {
            throw LocaleDataException::bundleMissing('codeMappings');
        }

        $map = [];

        foreach ($rows as $row) {
            if (!$row instanceof \ResourceBundle) {
                continue;
            }

            $fields = [];

            foreach ($row as $field) {
                $fields[] = (string) $field;
            }

            if (\count($fields) < 3 || $fields[0] === '') {
                continue;
            }

            // No padding. Bundle VALUES keep their leading zeros — measured:
            // Albania's numeric code arrives as the string `'008'`. Only KEYS
            // are canonicalised to int, and that is handled in containment().
            $map[$fields[0]] = [$fields[1], $fields[2]];
        }

        return $this->codeMappings = $map;
    }

    /**
     * child region or M49 group => its immediate parent M49 group.
     *
     * READ THE CLASS DOCBLOCK BEFORE CHANGING THIS. The group KEY passes
     * through `(string)` because PHP hands back `int 150` for `'150'` while
     * `'002'` stays a string. Guarding on `is_string()` here is the bug that
     * dropped Europe and Asia entirely.
     *
     * Both sides also pass through {@see m49()}, which zero-pads a numeric
     * code to three characters. That is belt and braces and NOT pinned — a
     * revert check removing it passes the suite, because PHP canonicalises
     * `'150'` and `150` to the SAME array key on both the storing and the
     * reading side. It stays so that a code taken out of this map and used as
     * a string rather than a key is unambiguous.
     *
     * @return array<string, string>
     */
    private function containment(): array
    {
        if ($this->containment !== null) {
            return $this->containment;
        }

        $tree = $this->supplemental()->get('territoryContainment');

        if (!$tree instanceof \ResourceBundle) {
            throw LocaleDataException::bundleMissing('territoryContainment');
        }

        $parent = [];

        foreach (iterator_to_array($tree) as $group => $members) {
            $code = self::m49((string) $group);

            if (isset(self::NOT_A_GROUP[$code]) || !$members instanceof \ResourceBundle) {
                continue;
            }

            foreach ($members as $member) {
                $parent[self::m49((string) $member)] = $code;
            }
        }

        return $this->containment = $parent;
    }

    /** Zero-pad a numeric M49 code back to three characters; leave letters alone. */
    private static function m49(string $code): string
    {
        return ctype_digit($code) ? str_pad($code, 3, '0', \STR_PAD_LEFT) : $code;
    }

    /**
     * Walk a country up the M49 tree until a group that defines a continent.
     *
     * The {@see Continent::OVERRIDES} check comes FIRST, because the override
     * exists precisely to contradict what the walk would find: CLDR places
     * Antarctica under Outlying Oceania, so walking it returns `OC`.
     */
    private function continentOf(string $alpha2): string
    {
        if (isset(Continent::OVERRIDES[$alpha2])) {
            return Continent::OVERRIDES[$alpha2];
        }

        $parent = $this->containment();
        $code   = $alpha2;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if (!isset($parent[$code])) {
                break;
            }

            $code = $parent[$code];

            if (isset(Continent::GROUP_TO_CONTINENT[$code])) {
                return Continent::GROUP_TO_CONTINENT[$code];
            }
        }

        // Unreachable for every ISO 3166-1 country on a sane ICU — there is a
        // test asserting all 250 resolve. It throws rather than returning a
        // placeholder, because a country silently filed under '' or 'XX' is
        // exactly the invisible-wrong-data failure this class exists to avoid.
        throw LocaleDataException::noContinent($alpha2);
    }

    /** @return array<string, string> currency alpha => zero-padded numeric */
    private function currencyNumerics(): array
    {
        if ($this->currencyNumerics !== null) {
            return $this->currencyNumerics;
        }

        $rows = $this->supplemental()->get('codeMappingsCurrency');
        $map  = [];

        if ($rows instanceof \ResourceBundle) {
            foreach ($rows as $row) {
                if (!$row instanceof \ResourceBundle) {
                    continue;
                }

                $fields = [];

                foreach ($row as $field) {
                    $fields[] = (string) $field;
                }

                if (\count($fields) >= 2 && $fields[0] !== '') {
                    // Unpadded, for the same measured reason as above: values
                    // keep their zeros.
                    $map[$fields[0]] = $fields[1];
                }
            }
        }

        return $this->currencyNumerics = $map;
    }

    private function supplemental(): \ResourceBundle
    {
        // Not memoized, and that is on purpose: ResourceBundle holds a handle
        // to mapped ICU data that the extension caches itself, so holding one
        // in a request-scoped object buys nothing and makes the object
        // un-serialisable.
        $bundle = new \ResourceBundle('supplementalData', 'ICUDATA', false);

        /** @phpstan-ignore-next-line the constructor can yield null on a broken ICU */
        if (!$bundle instanceof \ResourceBundle) {
            throw LocaleDataException::bundleMissing('supplementalData');
        }

        return $bundle;
    }

    // ── Localized names ──────────────────────────────────────────────────────

    /**
     * A region's localized name.
     *
     * `und-XX` and not bare `XX`: `Locale::getDisplayRegion('US')` parses its
     * argument as a LOCALE, and `'US'` as a locale is the language `us`, which
     * has no region — so it returns an empty string. The undetermined-language
     * prefix is what makes the argument mean "the region US".
     */
    private function regionName(string $region, string $locale): string
    {
        $name = \Locale::getDisplayRegion('und-' . $region, $locale);

        // ICU returns the code itself when it has no name for a region, which
        // is a usable fallback and better than an empty label in a picker.
        return $name !== '' ? $name : $region;
    }

    /**
     * The currency's localized long name, or the code when CLDR has none.
     *
     * There is no `Locale::getDisplayCurrency()` — the only display API for
     * currencies is `NumberFormatter`, which gives the SYMBOL and not the
     * name. So the name is read from the locale's own `curr` resource bundle,
     * which is where ICU keeps it: `Currencies.<CODE>` is the pair
     * [symbol, displayName]. Measured on ICU 79 — `en`/`USD` gives
     * ["$", "US Dollar"], `fr`/`USD` gives ["$US", "dollar des États-Unis"].
     *
     * Falls back to the CODE, never to a guess.
     */
    private function currencyName(string $code, string $locale): string
    {
        return $this->currencyDisplayName($code, $locale) ?? $code;
    }

    /** @return string|null null when the locale has no bundle or no entry */
    private function currencyDisplayName(string $code, string $locale): ?string
    {
        $bundle = new \ResourceBundle($locale, 'ICUDATA-curr', true);

        /** @phpstan-ignore-next-line null on a locale with no currency bundle */
        if (!$bundle instanceof \ResourceBundle) {
            return null;
        }

        $currencies = $bundle->get('Currencies');

        if (!$currencies instanceof \ResourceBundle) {
            return null;
        }

        $entry = $currencies->get($code);

        if (!$entry instanceof \ResourceBundle) {
            return null;
        }

        // The entry is [symbol, displayName].
        $fields = [];

        foreach ($entry as $field) {
            $fields[] = (string) $field;
        }

        return ($fields[1] ?? '') !== '' ? $fields[1] : null;
    }

    private function currencySymbol(string $code, string $locale): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $code);

        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);

        // ICU returns the CODE when a locale has no distinct symbol — `CDF`
        // for the Congolese franc. That is not a missing value: it is CLDR
        // saying the code is what should be displayed, and substituting
        // anything would print a symbol no local reader uses.
        return $symbol !== false && $symbol !== '' ? $symbol : $code;
    }

    /**
     * Minor units per major unit, as a power of ten.
     *
     * The field money code depends on, and it is not 2 everywhere: 0 for JPY
     * and UGX, 3 for KWD and BHD. Read from ICU rather than assumed, because
     * assuming 2 is a factor-of-100 billing error in both directions.
     *
     * The locale is irrelevant to this value — the number of minor units is a
     * property of the CURRENCY, not of who is reading about it — so a fixed
     * neutral locale is used. Passing the request locale would invite the
     * belief that it varies.
     */
    private function currencyDigits(string $code): int
    {
        $formatter = new \NumberFormatter('en', \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $code);

        $digits = $formatter->getAttribute(\NumberFormatter::FRACTION_DIGITS);

        return $digits !== false && $digits >= 0 ? $digits : 2;
    }
}
