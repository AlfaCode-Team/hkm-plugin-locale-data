<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Application\Ports;

use Plugins\LocaleData\Domain\Continent;
use Plugins\LocaleData\Domain\Country;
use Plugins\LocaleData\Domain\Currency;
use Plugins\LocaleData\Domain\Language;

/**
 * Where the reference data comes from.
 *
 * An interface because the data source is an implementation detail, and because
 * the service's own behaviour — sorting, filtering, caching, locale fallback —
 * has to be testable without ICU deciding the answers. The shipped
 * implementation is {@see \Plugins\LocaleData\Infrastructure\Gateways\IcuLocaleCatalogue}.
 *
 * Each method takes an already-RESOLVED locale string. Working out which locale
 * to use is the service's job, not the catalogue's, so that the fallback rules
 * live in one place and a second implementation cannot disagree with them.
 *
 * @see \Plugins\LocaleData\API\Contracts\LocaleDataContract for the public surface
 */
interface LocaleCatalogue
{
    /**
     * Every ISO 3166-1 country, unordered.
     *
     * Ordering is the service's job: a correct alphabetical order is
     * locale-dependent (a `Collator`, not `sort()`), and a catalogue that
     * pre-sorted would either do it wrong or do it twice.
     *
     * @return list<Country>
     */
    public function countries(string $locale): array;

    /** @return list<Continent> */
    public function continents(string $locale): array;

    /** @return list<Currency> */
    public function currencies(string $locale): array;

    /** @return list<Language> */
    public function languages(string $locale): array;

    /**
     * What this catalogue is, for a status line.
     *
     * Must name the data's PROVENANCE AND VERSION. The whole point of deriving
     * from the system's ICU is that the data is as current as that ICU — which
     * means an operator has to be able to see which one is installed without
     * reading the plugin's source.
     *
     * @return array<string, scalar>
     */
    public function describe(): array;
}
