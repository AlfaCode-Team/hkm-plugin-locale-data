<?php

declare(strict_types=1);

namespace Tests\Plugins\LocaleData\Support;

use Plugins\LocaleData\Application\Ports\LocaleCatalogue;
use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Domain\Country;
use Plugins\LocaleData\Domain\Currency;
use Plugins\LocaleData\Domain\Language;

/**
 * A catalogue whose answers the test chooses.
 *
 * It exists so the unit suite asserts THIS PLUGIN'S behaviour — ordering,
 * locale fallback, lookup, memoization — rather than ICU's opinions. Driving
 * those tests through the real catalogue would mean every assertion about
 * sorting was also an assertion about what CLDR calls a country this year, and
 * the suite would go red on an ICU upgrade for a reason that had nothing to do
 * with the code.
 *
 * It also COUNTS CALLS, which is the only way to show that memoization and the
 * cache actually prevent the expensive rebuild.
 */
final class FakeCatalogue implements LocaleCatalogue
{
    /** @var array<string, int> method => times called */
    public array $calls = ['countries' => 0, 'continents' => 0, 'currencies' => 0, 'languages' => 0];

    /** @var list<string> every locale this was asked for, in order */
    public array $locales = [];

    /**
     * @param list<Country>   $countries
     * @param list<Continent> $continents
     * @param list<Currency>  $currencies
     * @param list<Language>  $languages
     */
    public function __construct(
        private readonly array $countries = [],
        private readonly array $continents = [],
        private readonly array $currencies = [],
        private readonly array $languages = [],
    ) {}

    /** @return list<Country> */
    public function countries(string $locale): array
    {
        $this->calls['countries']++;
        $this->locales[] = $locale;

        return $this->countries;
    }

    /** @return list<Continent> */
    public function continents(string $locale): array
    {
        $this->calls['continents']++;
        $this->locales[] = $locale;

        return $this->continents;
    }

    /** @return list<Currency> */
    public function currencies(string $locale): array
    {
        $this->calls['currencies']++;
        $this->locales[] = $locale;

        return $this->currencies;
    }

    /** @return list<Language> */
    public function languages(string $locale): array
    {
        $this->calls['languages']++;
        $this->locales[] = $locale;

        return $this->languages;
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return ['source' => 'fake', 'icu' => 'test'];
    }

    // ── Builders, so a test states only the field it is about ────────────────

    public static function country(
        string $alpha2,
        string $name,
        string $continent = Continent::EUROPE,
        bool $isEu = false,
        string $alpha3 = '',
        string $numeric = '000',
    ): Country {
        return new Country(
            alpha2:    $alpha2,
            alpha3:    $alpha3 !== '' ? $alpha3 : $alpha2 . 'X',
            numeric:   $numeric,
            name:      $name,
            continent: $continent,
            isEu:      $isEu,
        );
    }

    public static function currency(string $code, string $name, int $digits = 2, string $symbol = ''): Currency
    {
        return new Currency(
            code:          $code,
            numeric:       '000',
            symbol:        $symbol !== '' ? $symbol : $code,
            name:          $name,
            decimalDigits: $digits,
        );
    }

    public static function language(string $code, string $name, string $native = ''): Language
    {
        return new Language($code, $name, $native !== '' ? $native : $name);
    }
}
