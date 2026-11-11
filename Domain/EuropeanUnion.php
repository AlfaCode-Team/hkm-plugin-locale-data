<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Domain;

/**
 * The twenty-seven member states of the European Union.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ONLY HAND-WRITTEN LIST IN THIS PLUGIN, AND WHY IT HAS TO BE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Everything else here is derived from the system's CLDR data through ICU.
 * CLDR does NOT carry EU membership: `territoryContainment` has no `EU` key,
 * which was checked rather than assumed. So this is a literal list, and it is
 * the one piece of reference data in this plugin that can go out of date
 * without the operating system noticing.
 *
 * It is a defensible list to hand-write because it is small, slow-moving and
 * unambiguous: twenty-seven states, with one change in the last decade (the
 * United Kingdom left on 31 January 2020 and is absent below). An accession
 * requires editing one line here.
 *
 * It is a list worth having because it decides real behaviour — VAT treatment,
 * GDPR applicability, the cookie banner, Strong Customer Authentication. An
 * application that needs it will otherwise write its own copy, and a copy in
 * every project is a copy that is wrong in one of them.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS NOT
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Not the EEA (adds Iceland, Liechtenstein, Norway), not the Schengen Area
 * (adds Switzerland, excludes Ireland and Cyprus), not the Eurozone (twenty of
 * these twenty-seven). Each is a different question with a different list, and
 * labelling this one "Europe" or using it for any of those is the mistake it
 * invites. `isEu` on {@see Country} means exactly "member state of the
 * European Union" and nothing more.
 */
final class EuropeanUnion
{
    /**
     * ISO 3166-1 alpha-2 codes of the member states, alphabetically.
     *
     * Correct as of 2026-11-11. Croatia (2013) is the most recent accession;
     * the United Kingdom's withdrawal (2020) is reflected by its absence.
     *
     * @var list<string>
     */
    public const MEMBERS = [
        'AT', // Austria
        'BE', // Belgium
        'BG', // Bulgaria
        'CY', // Cyprus
        'CZ', // Czechia
        'DE', // Germany
        'DK', // Denmark
        'EE', // Estonia
        'ES', // Spain
        'FI', // Finland
        'FR', // France
        'GR', // Greece
        'HR', // Croatia
        'HU', // Hungary
        'IE', // Ireland
        'IT', // Italy
        'LT', // Lithuania
        'LU', // Luxembourg
        'LV', // Latvia
        'MT', // Malta
        'NL', // Netherlands
        'PL', // Poland
        'PT', // Portugal
        'RO', // Romania
        'SE', // Sweden
        'SI', // Slovenia
        'SK', // Slovakia
    ];

    public static function includes(string $alpha2): bool
    {
        // A linear scan, and deliberately not a memoized lookup set. Building
        // the country list calls this 250 times, so the scan costs at most
        // 250 × 27 string comparisons once per locale — nothing against the
        // ICU calls happening alongside it. A `static` property to hold the
        // set would be request-lifetime state in a plugin class, which is what
        // leaks between requests under OpenSwoole; it would be harmless HERE,
        // because the value derives from a constant and cannot differ per
        // request, but "harmless this time" is how that pattern gets copied to
        // somewhere it is not.
        return \in_array(strtoupper($alpha2), self::MEMBERS, true);
    }

    public static function count(): int
    {
        return \count(self::MEMBERS);
    }
}
