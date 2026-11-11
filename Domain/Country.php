<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Domain;

/**
 * One ISO 3166-1 country or territory.
 *
 * Every field except `isEu` comes from CLDR through ICU, so this is a VIEW of
 * the data the operating system already has rather than a copy of it. See
 * {@see \Plugins\LocaleData\Infrastructure\Gateways\IcuLocaleCatalogue} for why
 * that matters and what it costs.
 */
final readonly class Country
{
    public function __construct(
        /** ISO 3166-1 alpha-2, upper case. The key everything else is looked up by. */
        public string $alpha2,
        /** ISO 3166-1 alpha-3. */
        public string $alpha3,
        /**
         * ISO 3166-1 numeric, as a THREE-CHARACTER STRING and never an int.
         *
         * `004` is Afghanistan. As an int it is 4, and the leading zeros are
         * part of the code — every standard, form and downstream system writes
         * it with them. Typed as `string` so that nothing downstream can
         * canonicalise it away: `'004'` used as an array key stays `'004'`,
         * while `4` becomes a different key entirely.
         */
        public string $numeric,
        /** Localized display name, in the locale that was asked for. */
        public string $name,
        /** One of {@see Continent}'s seven codes. */
        public string $continent,
        /** Whether this is a member state of the European Union. */
        public bool $isEu,
    ) {}

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return [
            'alpha2'    => $this->alpha2,
            'alpha3'    => $this->alpha3,
            'numeric'   => $this->numeric,
            'name'      => $this->name,
            'continent' => $this->continent,
            'is_eu'     => $this->isEu,
        ];
    }
}
