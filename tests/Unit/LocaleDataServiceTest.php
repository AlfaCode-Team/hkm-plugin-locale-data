<?php

declare(strict_types=1);

namespace Tests\Plugins\LocaleData\Unit;

use AlfacodeTeam\Ground\Fakes\FakeCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LocaleData\Application\Services\LocaleDataService;
use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Infrastructure\Config\LocaleDataConfig;
use Tests\Plugins\LocaleData\Support\FakeCatalogue;

/**
 * The service's own behaviour, against a catalogue whose answers the test
 * chooses — so nothing here is also an assertion about what CLDR calls a
 * country this year.
 */
#[CoversClass(LocaleDataService::class)]
final class LocaleDataServiceTest extends TestCase
{
    private function catalogue(): FakeCatalogue
    {
        return new FakeCatalogue(
            countries: [
                FakeCatalogue::country('ZW', 'Zimbabwe', Continent::AFRICA),
                FakeCatalogue::country('CI', "Côte d'Ivoire", Continent::AFRICA),
                FakeCatalogue::country('DE', 'Allemagne', Continent::EUROPE, isEu: true),
                FakeCatalogue::country('EG', 'Égypte', Continent::AFRICA),
                FakeCatalogue::country('FR', 'France', Continent::EUROPE, isEu: true),
                FakeCatalogue::country('US', 'États-Unis', Continent::NORTH_AMERICA),
            ],
            continents: [
                new Continent(Continent::EUROPE, 'Europe'),
                new Continent(Continent::AFRICA, 'Afrique'),
            ],
            currencies: [
                FakeCatalogue::currency('USD', 'dollar des États-Unis'),
                FakeCatalogue::currency('EUR', 'euro'),
                FakeCatalogue::currency('JPY', 'yen japonais', digits: 0),
            ],
            languages: [
                FakeCatalogue::language('de', 'allemand', 'Deutsch'),
                FakeCatalogue::language('en', 'anglais', 'English'),
            ],
        );
    }

    private function service(
        ?FakeCatalogue $catalogue = null,
        ?LocaleDataConfig $config = null,
        ?FakeCache $cache = null,
    ): LocaleDataService {
        return new LocaleDataService(
            $catalogue ?? $this->catalogue(),
            $config ?? LocaleDataConfig::of(defaultLocale: 'fr'),
            $cache,
        );
    }

    // ── Collation: the reason sortByName() exists ───────────────────────────

    public function test_countries_are_collated_and_not_sorted_by_bytes(): void
    {
        // THE test this plugin's ordering exists for. A byte-wise sort puts
        // every accented name after every unaccented one, so in a French list
        // `Côte d'Ivoire`, `Égypte` and `États-Unis` all land below Zimbabwe —
        // a third of the names in a heap at the bottom. A Collator puts them
        // where a French reader looks for them.
        $names = array_map(
            static fn ($c): string => $c->name,
            $this->service()->countries('fr'),
        );

        self::assertSame(
            ['Allemagne', "Côte d'Ivoire", 'Égypte', 'États-Unis', 'France', 'Zimbabwe'],
            $names,
        );

        // And to be explicit about what the alternative would have produced.
        // `É` is U+00C9, which is two bytes starting 0xC3 — above every ASCII
        // letter — so a byte sort exiles both É names past Zimbabwe. `Côte`
        // is unaffected because it starts with a plain ASCII `C`; only the
        // names whose FIRST character is accented move, which is exactly what
        // makes the bug easy to miss on a small test list and obvious on a
        // real one.
        $byBytes = $names;
        sort($byBytes);

        self::assertNotSame($names, $byBytes, 'a byte sort must differ, or this proves nothing');
        self::assertSame(
            ['Allemagne', "Côte d'Ivoire", 'France', 'Zimbabwe', 'Égypte', 'États-Unis'],
            $byBytes,
        );
        self::assertSame(
            ['Égypte', 'États-Unis'],
            \array_slice($byBytes, -2),
            'sort() dumps the accented names below Zimbabwe; the Collator does not',
        );
    }

    public function test_continents_are_sorted_by_localized_name(): void
    {
        self::assertSame(
            ['Afrique', 'Europe'],
            array_map(static fn ($c): string => $c->name, $this->service()->continents('fr')),
        );
    }

    public function test_languages_are_sorted_by_localized_name(): void
    {
        self::assertSame(
            ['allemand', 'anglais'],
            array_map(static fn ($l): string => $l->name, $this->service()->languages('fr')),
        );
    }

    public function test_currencies_are_sorted_by_code_and_not_by_name(): void
    {
        // The one list not ordered by label, on purpose: a currency list is
        // read and searched by its CODE. Somebody looking for USD scans for
        // "USD", not for "dollar des États-Unis" filed under D.
        self::assertSame(
            ['EUR', 'JPY', 'USD'],
            array_map(static fn ($c): string => $c->code, $this->service()->currencies('fr')),
        );
    }

    // ── Locale resolution ────────────────────────────────────────────────────

    public function test_a_null_locale_uses_the_configured_default(): void
    {
        $catalogue = $this->catalogue();

        $this->service($catalogue, LocaleDataConfig::of(defaultLocale: 'sw'))->countries();

        self::assertSame(['sw'], $catalogue->locales);
    }

    public function test_an_empty_or_whitespace_locale_uses_the_default(): void
    {
        $catalogue = $this->catalogue();
        $service   = $this->service($catalogue, LocaleDataConfig::of(defaultLocale: 'sw'));

        $service->countries('');
        $service->continents('   ');

        self::assertSame(['sw', 'sw'], $catalogue->locales);
    }

    public function test_an_explicit_locale_is_passed_through(): void
    {
        $catalogue = $this->catalogue();

        $this->service($catalogue)->countries('ar');

        self::assertSame(['ar'], $catalogue->locales);
    }

    public function test_an_unknown_locale_is_not_an_error(): void
    {
        // A bad Accept-Language from a client must not 500 a country list. ICU
        // resolves an unknown tag to its root data; the list comes back, just
        // in English.
        self::assertCount(6, $this->service()->countries('zz-ZZ'));
    }

    // ── Lookup ───────────────────────────────────────────────────────────────

    public function test_a_country_is_found_by_code_case_insensitively(): void
    {
        $service = $this->service();

        self::assertSame('France', $service->country('FR')?->name);
        self::assertSame('France', $service->country('fr')?->name);
        self::assertSame('France', $service->country(' Fr ')?->name, 'and trimmed');
    }

    public function test_an_unknown_code_returns_null_rather_than_throwing(): void
    {
        // An unknown country code in a form is a caller's input, and the caller
        // decides what to do about it. Throwing would make every lookup a
        // try/catch.
        $service = $this->service();

        self::assertNull($service->country('XX'));
        self::assertNull($service->currency('XXX'));
        self::assertNull($service->language('xx'));
        self::assertNull($service->country(''));
    }

    public function test_a_currency_and_a_language_are_found_by_code(): void
    {
        $service = $this->service();

        self::assertSame(0, $service->currency('jpy')?->decimalDigits);
        self::assertSame('Deutsch', $service->language('DE')?->nativeName);
    }

    // ── Filtering ────────────────────────────────────────────────────────────

    public function test_eu_countries_are_the_flagged_ones_in_list_order(): void
    {
        self::assertSame(
            ['DE', 'FR'],
            array_map(static fn ($c): string => $c->alpha2, $this->service()->euCountries('fr')),
        );
    }

    public function test_countries_in_a_continent_keep_the_list_order(): void
    {
        // Filtered from the already-sorted list rather than sorted again, so a
        // continent's countries appear in the same order they do in the full
        // list — which is what makes a grouped picker consistent.
        self::assertSame(
            ['CI', 'EG', 'ZW'],
            array_map(static fn ($c): string => $c->alpha2, $this->service()->countriesIn('AF', 'fr')),
        );
    }

    public function test_a_continent_code_is_matched_case_insensitively(): void
    {
        self::assertCount(3, $this->service()->countriesIn('af', 'fr'));
        self::assertCount(3, $this->service()->countriesIn(' AF ', 'fr'));
    }

    public function test_an_unknown_continent_is_empty_and_not_an_error(): void
    {
        self::assertSame([], $this->service()->countriesIn('XX', 'fr'));
    }

    // ── Memoization and caching ──────────────────────────────────────────────

    public function test_a_list_is_built_once_per_locale_per_instance(): void
    {
        $catalogue = $this->catalogue();
        $service   = $this->service($catalogue);

        $service->countries('fr');
        $service->countries('fr');
        $service->country('FR', 'fr');
        $service->euCountries('fr');
        $service->countriesIn('AF', 'fr');

        self::assertSame(1, $catalogue->calls['countries'], 'five calls, one build');
    }

    public function test_a_different_locale_is_a_different_build(): void
    {
        $catalogue = $this->catalogue();
        $service   = $this->service($catalogue);

        $service->countries('fr');
        $service->countries('en');
        $service->countries('fr');

        self::assertSame(2, $catalogue->calls['countries']);
    }

    public function test_a_bound_cache_is_used_and_its_key_carries_the_icu_version(): void
    {
        // THE cache-key detail that matters. Without the ICU version in the
        // key, a server upgraded to a new ICU keeps serving the previous one's
        // country names out of Redis until the TTL runs out — and the TTL is a
        // day, on data that only moves when ICU moves. That is precisely the
        // moment the cache is wrong and nothing says so.
        $cache   = new FakeCache();
        $service = $this->service(cache: $cache);

        $service->countries('fr');

        $keys = $cache->keys();

        self::assertCount(1, $keys);
        self::assertStringContainsString(INTL_ICU_VERSION, $keys[0]);
        self::assertStringContainsString('countries', $keys[0]);
        self::assertStringContainsString('fr', $keys[0]);
    }

    public function test_a_zero_ttl_turns_caching_off_without_turning_memoization_off(): void
    {
        $catalogue = $this->catalogue();
        $cache     = new FakeCache();
        $service   = $this->service(
            $catalogue,
            LocaleDataConfig::of(defaultLocale: 'fr', cacheTtl: 0),
            $cache,
        );

        $service->countries('fr');
        $service->countries('fr');

        self::assertSame([], $cache->keys(), 'nothing written');
        self::assertSame(1, $catalogue->calls['countries'], 'still built once');
    }

    public function test_a_second_instance_is_answered_from_the_cache(): void
    {
        $catalogue = $this->catalogue();
        $cache     = new FakeCache();

        $first = $this->service($catalogue, cache: $cache)->countries('fr');
        // A SECOND service instance, so the per-instance memo cannot be what
        // answers — the value has to come back out of the cache.
        $second = $this->service($catalogue, cache: $cache)->countries('fr');

        self::assertEquals($first, $second);
        self::assertSame(1, $catalogue->calls['countries'], 'the second instance rebuilt nothing');
    }

    public function test_value_objects_survive_php_serialization(): void
    {
        // The requirement this plugin places on the cache DRIVER, tested
        // against the mechanism the drivers actually use. FakeCache holds
        // values in an array, so the test above cannot show this — it would
        // pass for a driver that mangled objects.
        //
        // Value objects are cached rather than their array form, so a driver
        // that did not round-trip objects would hand back arrays and every
        // property access downstream would fail with "Attempt to read property
        // on array". Every first-party adapter PHP-serializes anything
        // non-numeric, which is why this holds; `readonly` promoted properties
        // are the part worth confirming.
        $countries = $this->service()->countries('fr');

        /** @var list<\Plugins\LocaleData\Domain\Country> $restored */
        $restored = unserialize(serialize($countries), ['allowed_classes' => true]);

        self::assertEquals($countries, $restored);
        self::assertSame('France', $restored[4]->name);
        self::assertTrue($restored[0]->isEu, 'Allemagne, first after collation');
    }

    // ── describe() ───────────────────────────────────────────────────────────

    public function test_describe_names_the_source_and_whether_a_cache_is_bound(): void
    {
        self::assertStringContainsString('source=fake', $this->service()->describe());
        self::assertStringContainsString('cache=none', $this->service()->describe());
        self::assertStringContainsString('cache=bound', $this->service(cache: new FakeCache())->describe());
        self::assertStringContainsString('default_locale=fr', $this->service()->describe());
    }
}
