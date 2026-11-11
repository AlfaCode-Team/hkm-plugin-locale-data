<?php

declare(strict_types=1);

namespace Tests\Plugins\LocaleData\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\LocaleData\Application\Services\LocaleDataService;
use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Domain\Country;
use Plugins\LocaleData\Domain\EuropeanUnion;
use Plugins\LocaleData\Infrastructure\Config\LocaleDataConfig;
use Plugins\LocaleData\Infrastructure\Gateways\IcuLocaleCatalogue;

/**
 * The real ICU.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT ONLY THIS SUITE CAN SHOW, AND WHAT IT CANNOT PROMISE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * It is the only place the DERIVATION is exercised: the containment walk, the
 * range-token expansion, the zero-padding of numeric codes, and the two
 * numeric-key traps that cost this plugin its continent mapping on the first
 * attempt.
 *
 * It is also the suite whose numbers move when the operating system's ICU is
 * upgraded. So the assertions are deliberately split:
 *
 *   STRUCTURAL claims are exact — every country has three codes, all 250
 *   resolve to a continent, no continent is empty, numeric codes are three
 *   characters. None of those depend on which CLDR shipped.
 *
 *   COUNT claims are RANGES, because a country being admitted or a territory
 *   reclassified is CLDR doing its job and must not turn this suite red.
 *
 *   NAME claims are made only where the name is stable, and never for one
 *   that CLDR has already changed once — `Türkiye` is the cautionary example
 *   and is deliberately not asserted.
 */
#[CoversClass(IcuLocaleCatalogue::class)]
final class IcuLocaleCatalogueTest extends TestCase
{
    private IcuLocaleCatalogue $catalogue;

    protected function setUp(): void
    {
        if (!\extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is required; this plugin has no fallback by design.');
        }

        $this->catalogue = new IcuLocaleCatalogue();
    }

    private function service(string $locale = 'en'): LocaleDataService
    {
        return new LocaleDataService(
            $this->catalogue,
            LocaleDataConfig::of(defaultLocale: $locale),
            null,
        );
    }

    /** @return array<string, Country> */
    private function byCode(string $locale = 'en'): array
    {
        $map = [];

        foreach ($this->catalogue->countries($locale) as $country) {
            $map[$country->alpha2] = $country;
        }

        return $map;
    }

    // ── The country set ──────────────────────────────────────────────────────

    public function test_about_250_countries_come_back(): void
    {
        // A RANGE, not a number. ISO 3166-1 has 249 assigned alpha-2 codes and
        // CLDR adds XK (Kosovo), which has no ISO assignment and is in every
        // address form. An accession or a reclassification must not turn this
        // red.
        $count = \count($this->catalogue->countries('en'));

        self::assertGreaterThanOrEqual(240, $count);
        self::assertLessThanOrEqual(260, $count);
    }

    public function test_cldrs_exceptionally_reserved_codes_are_excluded(): void
    {
        // AC, CP, DG, EA, IC, TA and CQ are real CLDR regions and NOT ISO
        // 3166-1 assigned countries — Ascension, Clipperton, Diego Garcia,
        // Ceuta & Melilla, the Canary Islands, Tristan da Cunha, Sark. They
        // are filtered out by requiring an ISO code mapping, and a picker
        // offering "Diego Garcia" next to "Germany" is a picker with a bug.
        $codes = $this->byCode();

        foreach (['AC', 'CP', 'DG', 'EA', 'IC', 'TA', 'CQ'] as $reserved) {
            self::assertArrayNotHasKey($reserved, $codes, $reserved);
        }
    }

    public function test_cldrs_user_assigned_codes_are_excluded(): void
    {
        // `codeMappings` carries 303 entries including `AA`, which is
        // user-assigned and not a country. Requiring it to also be a "regular"
        // region filters those out — which is why BOTH halves of the filter
        // are needed and neither alone would do.
        self::assertArrayNotHasKey('AA', $this->byCode());
    }

    public function test_every_country_has_all_three_iso_codes(): void
    {
        foreach ($this->catalogue->countries('en') as $country) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $country->alpha2, $country->alpha2);
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $country->alpha3, $country->alpha2);
            self::assertMatchesRegularExpression('/^[0-9]{3}$/', $country->numeric, $country->alpha2);
            self::assertNotSame('', $country->name, $country->alpha2);
        }
    }

    /** @return list<array{string, string, string}> */
    public static function knownCodes(): array
    {
        // Codes, never names: a code is fixed by the standard, and a name is
        // CLDR's and can change (see the class docblock on Türkiye).
        return [
            ['US', 'USA', '840'],
            ['GB', 'GBR', '826'],
            ['FR', 'FRA', '250'],
            ['DE', 'DEU', '276'],
            ['JP', 'JPN', '392'],
            ['CD', 'COD', '180'],
            ['UG', 'UGA', '800'],
            ['BR', 'BRA', '076'],
            // The leading-zero cases, which are the whole reason the numeric
            // code is typed `string`. ICU hands these back WITH their zeros —
            // measured, after a first version padded them "to fix" a problem
            // that only affects bundle KEYS — so this asserts the contract
            // rather than a repair.
            ['AL', 'ALB', '008'],
            ['AF', 'AFG', '004'],
            ['DZ', 'DZA', '012'],
        ];
    }

    #[DataProvider('knownCodes')]
    public function test_a_known_country_has_the_codes_the_standard_gives_it(
        string $alpha2,
        string $alpha3,
        string $numeric,
    ): void {
        $country = $this->byCode()[$alpha2] ?? null;

        self::assertNotNull($country, $alpha2);
        self::assertSame($alpha3, $country->alpha3);
        self::assertSame($numeric, $country->numeric);
    }

    public function test_a_numeric_code_is_a_three_character_string(): void
    {
        // `004` is Afghanistan, and every standard, form and downstream system
        // writes the zeros. The type is what protects them: `'004'` used as an
        // array key stays `'004'`, while `4` becomes a different key.
        $country = $this->byCode()['AF'];

        self::assertSame('004', $country->numeric);
        self::assertNotSame('4', $country->numeric);
        self::assertSame(3, \strlen($country->numeric));
    }

    // ── The continent walk — the trap this plugin was built around ──────────

    public function test_every_country_resolves_to_a_continent(): void
    {
        // THE regression test. The first version of the containment walk
        // guarded on `is_string($group)`, which silently dropped every M49
        // group whose code has no leading zero — Europe (150), Asia (142) and
        // all their sub-regions. 103 of 250 countries came back with no
        // continent, every one of them European or Asian, and nothing threw.
        $countries = $this->catalogue->countries('en');
        $valid     = Continent::codes();

        foreach ($countries as $country) {
            self::assertContains($country->continent, $valid, $country->alpha2 . ' / ' . $country->name);
        }

        self::assertCount(\count($countries), $countries);
    }

    public function test_no_continent_is_empty(): void
    {
        // The other face of the same bug: Europe and Asia had zero countries,
        // which is the symptom an operator would actually see.
        $service = $this->service();

        foreach (Continent::codes() as $code) {
            self::assertNotSame(
                [],
                $service->countriesIn($code),
                "continent {$code} has no countries — the containment walk is broken",
            );
        }
    }

    public function test_the_continents_partition_the_countries(): void
    {
        $service = $this->service();
        $total   = 0;

        foreach (Continent::codes() as $code) {
            $total += \count($service->countriesIn($code));
        }

        self::assertSame(
            \count($service->countries()),
            $total,
            'every country on exactly one continent',
        );
    }

    /** @return list<array{string, string}> */
    public static function continentPlacements(): array
    {
        return [
            // The override: CLDR files Antarctica under Outlying Oceania, so
            // walking the tree returns OC. This is the single place this
            // plugin contradicts CLDR, and it is pinned so the override cannot
            // be deleted silently.
            ['AQ', Continent::ANTARCTICA],

            // The Americas split, which M49 does not make: 019 is one
            // "Americas" and the model wants two.
            ['US', Continent::NORTH_AMERICA],
            ['MX', Continent::NORTH_AMERICA],
            ['PR', Continent::NORTH_AMERICA],
            ['BR', Continent::SOUTH_AMERICA],
            ['AR', Continent::SOUTH_AMERICA],

            // The transcontinental calls, which follow CLDR deliberately —
            // each has an argument against it and none has a right answer, so
            // deferring to CLDR beats this plugin having an opinion. Pinned so
            // a CLDR change is NOTICED rather than silently shipped.
            ['RU', Continent::EUROPE],
            ['TR', Continent::ASIA],
            ['CY', Continent::ASIA],
            ['GE', Continent::ASIA],
            ['AM', Continent::ASIA],
            ['AZ', Continent::ASIA],
            ['KZ', Continent::ASIA],

            ['EG', Continent::AFRICA],
            ['ZA', Continent::AFRICA],
            ['DE', Continent::EUROPE],
            ['JP', Continent::ASIA],
            ['AU', Continent::OCEANIA],
            ['FJ', Continent::OCEANIA],
        ];
    }

    #[DataProvider('continentPlacements')]
    public function test_a_country_is_placed_on_the_expected_continent(string $alpha2, string $expected): void
    {
        self::assertSame($expected, $this->byCode()[$alpha2]->continent ?? null, $alpha2);
    }

    public function test_the_seven_continents_all_have_a_localized_name(): void
    {
        $continents = $this->catalogue->continents('en');

        self::assertCount(7, $continents);

        foreach ($continents as $continent) {
            self::assertNotSame('', $continent->name, $continent->code);
            // Not just the code echoed back, which is the fallback — it would
            // mean CLDR had no name for the representative M49 group.
            self::assertNotSame($continent->code, $continent->name, $continent->code);
        }
    }

    public function test_a_continent_name_is_localized(): void
    {
        $names = static fn (array $list): array => array_combine(
            array_map(static fn ($c): string => $c->code, $list),
            array_map(static fn ($c): string => $c->name, $list),
        );

        $en = $names($this->catalogue->continents('en'));
        $fr = $names($this->catalogue->continents('fr'));

        self::assertSame('Africa', $en[Continent::AFRICA]);
        self::assertSame('Afrique', $fr[Continent::AFRICA]);
    }

    // ── EU membership ────────────────────────────────────────────────────────

    public function test_exactly_the_twenty_seven_member_states_are_flagged(): void
    {
        $flagged = array_map(
            static fn (Country $c): string => $c->alpha2,
            $this->service()->euCountries(),
        );

        sort($flagged);

        self::assertSame(EuropeanUnion::MEMBERS, $flagged);
        self::assertCount(27, $flagged);
    }

    public function test_the_united_kingdom_is_not_in_the_eu(): void
    {
        // The one membership change in the last decade, and the reason the
        // list is dated in its docblock.
        self::assertFalse($this->byCode()['GB']->isEu);
    }

    public function test_countries_that_are_in_europe_but_not_in_the_eu(): void
    {
        // The distinction `isEu` exists to make. Norway and Switzerland are in
        // the EEA and Schengen respectively and in neither case in the EU —
        // using this flag for either question is the mistake it invites.
        $codes = $this->byCode();

        foreach (['NO', 'CH', 'IS', 'GB', 'RS', 'UA'] as $code) {
            self::assertSame(Continent::EUROPE, $codes[$code]->continent, $code);
            self::assertFalse($codes[$code]->isEu, $code);
        }
    }

    // ── Currencies ───────────────────────────────────────────────────────────

    public function test_about_153_current_currencies_come_back(): void
    {
        $count = \count($this->catalogue->currencies('en'));

        self::assertGreaterThanOrEqual(140, $count);
        self::assertLessThanOrEqual(180, $count);
    }

    public function test_historical_currencies_are_excluded(): void
    {
        // `codeMappingsCurrency` has 183 entries including withdrawn codes.
        // The list comes from `idValidity.currency.regular` instead, because a
        // currency picker offering the Netherlands Antillean guilder or the
        // Deutsche Mark is a picker with a bug in it.
        $codes = array_map(static fn ($c): string => $c->code, $this->catalogue->currencies('en'));

        foreach (['DEM', 'FRF', 'ITL', 'ANG', 'ZWD'] as $withdrawn) {
            self::assertNotContains($withdrawn, $codes, $withdrawn);
        }

        self::assertContains('EUR', $codes);
        self::assertContains('USD', $codes);
    }

    /** @return list<array{string, int}> */
    public static function decimalDigits(): array
    {
        // THE field money code depends on, and it is not 2 everywhere.
        // Hard-coding 100 is a factor-of-100 billing error in both directions:
        // a 1,000 JPY charge billed as 10 JPY, or 1.000 KWD billed as 1,000.
        return [
            ['USD', 2], ['EUR', 2], ['GBP', 2], ['CDF', 2],
            ['JPY', 0], ['UGX', 0], ['KRW', 0], ['RWF', 0], ['XAF', 0], ['XOF', 0],
            ['KWD', 3], ['BHD', 3], ['JOD', 3], ['OMR', 3], ['TND', 3],
        ];
    }

    #[DataProvider('decimalDigits')]
    public function test_a_currencys_decimal_digits_come_from_icu(string $code, int $expected): void
    {
        $currency = $this->service()->currency($code);

        self::assertNotNull($currency, $code);
        self::assertSame($expected, $currency->decimalDigits, $code);
        self::assertSame(10 ** $expected, $currency->minorUnitsPerMajor(), $code);
    }

    public function test_a_currency_symbol_is_localized(): void
    {
        // Measured on ICU 79: `$` in en, `$US` in fr. Both are right for their
        // reader, which is why the symbol is resolved per locale rather than
        // stored once.
        self::assertSame('$', $this->service('en')->currency('USD')?->symbol);
        self::assertSame('$US', $this->service('fr')->currency('USD')?->symbol);
        self::assertSame('€', $this->service('en')->currency('EUR')?->symbol);
    }

    public function test_a_currency_with_no_distinct_symbol_reports_its_code(): void
    {
        // Not a missing value: CLDR saying there is no narrow symbol in this
        // locale and the code IS what should be displayed. Substituting a
        // guess would print a symbol no local reader uses.
        self::assertSame('CDF', $this->service('en')->currency('CDF')?->symbol);
    }

    public function test_a_currency_name_is_localized(): void
    {
        self::assertSame('Japanese Yen', $this->service('en')->currency('JPY')?->name);
        self::assertSame('yen japonais', $this->service('fr')->currency('JPY')?->name);
    }

    public function test_every_currency_has_a_name_and_a_symbol(): void
    {
        foreach ($this->catalogue->currencies('en') as $currency) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $currency->code);
            self::assertNotSame('', $currency->name, $currency->code);
            self::assertNotSame('', $currency->symbol, $currency->code);
            self::assertGreaterThanOrEqual(0, $currency->decimalDigits, $currency->code);
            self::assertLessThanOrEqual(4, $currency->decimalDigits, $currency->code);
        }
    }

    // ── Languages ────────────────────────────────────────────────────────────

    public function test_only_iso_639_1_two_letter_languages_come_back(): void
    {
        // CLDR's list is ISO 639-3 with over seven thousand entries — a picker
        // nobody can use. Filtering to length two IS ISO 639-1.
        $languages = $this->catalogue->languages('en');

        self::assertGreaterThanOrEqual(150, \count($languages));
        self::assertLessThanOrEqual(220, \count($languages));

        foreach ($languages as $language) {
            self::assertMatchesRegularExpression('/^[a-z]{2}$/', $language->code, $language->code);
        }
    }

    public function test_a_language_carries_its_native_name(): void
    {
        // What a language picker must show: a user who cannot read the current
        // interface language cannot read "German" either.
        $service = $this->service('en');

        self::assertSame('German', $service->language('de')?->name);
        self::assertSame('Deutsch', $service->language('de')?->nativeName);
        self::assertSame('Swahili', $service->language('sw')?->name);
        self::assertSame('Kiswahili', $service->language('sw')?->nativeName);
    }

    public function test_a_language_name_is_localized_but_the_native_name_is_not(): void
    {
        self::assertSame('allemand', $this->service('fr')->language('de')?->name);
        self::assertSame(
            'Deutsch',
            $this->service('fr')->language('de')?->nativeName,
            'the native name does not change with the reader',
        );
    }

    // ── Localized country names, and collation on the real list ─────────────

    public function test_a_country_name_is_localized(): void
    {
        // Names that CLDR has not changed and is unlikely to. `Türkiye` is
        // deliberately absent: CLDR renamed it in version 42, which is exactly
        // the kind of change this suite must not break on.
        self::assertSame('Germany', $this->byCode('en')['DE']->name);
        self::assertSame('Allemagne', $this->byCode('fr')['DE']->name);
        self::assertSame('France', $this->byCode('en')['FR']->name);
    }

    public function test_the_french_country_list_does_not_dump_accented_names_at_the_end(): void
    {
        // The collation test on the REAL list, where it matters: French has
        // many country names beginning with É — Égypte, Équateur, Éthiopie,
        // Émirats arabes unis, États-Unis — and a byte sort puts every one of
        // them below Zimbabwe.
        $names = array_map(
            static fn (Country $c): string => $c->name,
            $this->service('fr')->countries(),
        );

        $accented = array_values(array_filter(
            $names,
            static fn (string $n): bool => str_starts_with($n, 'É'),
        ));

        self::assertNotSame([], $accented, 'the fixture assumption: French has É country names');

        $last = array_slice($names, -\count($accented));

        self::assertNotSame(
            $accented,
            $last,
            'the É names are not all at the end, so a Collator is in use and not sort()',
        );
    }

    public function test_the_country_list_is_stably_ordered(): void
    {
        $first  = array_map(static fn (Country $c): string => $c->alpha2, $this->service()->countries());
        // A fresh service, so neither the memo nor a cache can be what makes
        // the two agree.
        $second = array_map(static fn (Country $c): string => $c->alpha2, $this->service()->countries());

        self::assertSame($first, $second);
    }

    // ── Provenance ───────────────────────────────────────────────────────────

    public function test_describe_reports_the_icu_version(): void
    {
        // The one value an operator needs, because the data is exactly as
        // current as this and two servers on different versions can disagree
        // about a country's NAME.
        $described = $this->catalogue->describe();

        self::assertSame('icu', $described['source']);
        self::assertSame(INTL_ICU_VERSION, $described['icu']);
        self::assertSame(27, $described['eu_members']);
        self::assertSame(7, $described['continents']);
        self::assertGreaterThan(200, $described['countries']);
    }

    public function test_describe_carries_no_credential(): void
    {
        $flat = json_encode(
            [...$this->catalogue->describe(), ...LocaleDataConfig::of()->describe()],
            \JSON_THROW_ON_ERROR,
        );

        foreach (['secret', 'password', 'dsn', 'token'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $flat, $forbidden);
        }
    }
}
