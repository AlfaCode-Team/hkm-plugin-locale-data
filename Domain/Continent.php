<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Domain;

/**
 * A continent, in the seven-continent model.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE SEVEN CONTINENTS ARE A DEFINITION, NOT DATA
 * ────────────────────────────────────────────────────────────────────────────
 *
 * CLDR does not have continents. It has the UN M49 region hierarchy, which
 * splits the world into FIVE macro-regions — Africa (002), Americas (019),
 * Europe (150), Asia (142), Oceania (009) — and has no concept of Antarctica
 * as a peer of those.
 *
 * The seven-continent model that every product's country picker uses is
 * therefore assembled from M49 groups, and that assembly is written here so it
 * is reviewable in one place:
 *
 *   AF  ← 002  Africa
 *   EU  ← 150  Europe
 *   AS  ← 142  Asia
 *   OC  ← 009  Oceania
 *   NA  ← 021 Northern America + 013 Central America + 029 Caribbean
 *   SA  ← 005  South America
 *   AN  ← Antarctica, which M49 does not place here at all (see below)
 *
 * `NA` and `SA` exist because M49 has one `019 Americas` and the model wants
 * two; the three northern sub-regions are grouped and South America stands
 * alone. That is the only place the model disagrees with M49's SHAPE.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * ANTARCTICA IS THE ONE PLACE WE OVERRIDE CLDR, AND IT IS MEASURED
 * ────────────────────────────────────────────────────────────────────────────
 *
 * CLDR puts `AQ` under `QO` (Outlying Oceania) under `009` (Oceania). Walking
 * the tree therefore returns OCEANIA for Antarctica — which is not a judgement
 * call, it is a continent nobody would accept in a country picker. So `AQ` is
 * mapped to `AN` explicitly, and that single override is the entire divergence
 * between this plugin and the system's CLDR.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TRANSCONTINENTAL COUNTRIES FOLLOW CLDR, DELIBERATELY
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Russia is EU, Turkey is AS, Cyprus is AS. Each of those has an argument
 * against it and none of them has a right answer — which is the reason to
 * defer to CLDR rather than to re-litigate them here. A hand-maintained list
 * of exceptions would be this plugin's opinion presented as reference data, and
 * it would drift from the names and codes that come from the same source.
 */
final readonly class Continent
{
    public const AFRICA        = 'AF';
    public const ANTARCTICA    = 'AN';
    public const ASIA          = 'AS';
    public const EUROPE        = 'EU';
    public const NORTH_AMERICA = 'NA';
    public const OCEANIA       = 'OC';
    public const SOUTH_AMERICA = 'SA';

    /**
     * Continent code → the M49 group whose localized name CLDR carries.
     *
     * The NAME is read from CLDR for the representative group, so
     * "Europe"/"Europe"/"أوروبا" come from the same source as every country
     * name and need no translation of our own. `NA` uses `021` (Northern
     * America) rather than `019`, because `019` is "Americas" and would print
     * the wrong label next to a `SA` entry.
     *
     * `AN` has no M49 group, so its name falls back to the region name of the
     * territory `AQ` — which CLDR does carry, localized.
     *
     * @var array<string, string>
     */
    public const M49_GROUP = [
        self::AFRICA        => '002',
        self::EUROPE        => '150',
        self::ASIA          => '142',
        self::OCEANIA       => '009',
        self::NORTH_AMERICA => '021',
        self::SOUTH_AMERICA => '005',
        self::ANTARCTICA    => 'AQ',
    ];

    /**
     * M49 group → continent code, for walking a country up the tree.
     *
     * The walk stops at the FIRST match, so the order these are found in does
     * not matter — but note that `021`, `013` and `029` all map to `NA` and are
     * each reached before `019`, which is why `019` is absent: a country that
     * reached `019` would be in the Americas with no side chosen.
     *
     * @var array<string, string>
     */
    public const GROUP_TO_CONTINENT = [
        '002' => self::AFRICA,
        '150' => self::EUROPE,
        '142' => self::ASIA,
        '009' => self::OCEANIA,
        '021' => self::NORTH_AMERICA,
        '013' => self::NORTH_AMERICA,
        '029' => self::NORTH_AMERICA,
        '005' => self::SOUTH_AMERICA,
    ];

    /** The one territory whose CLDR placement is overridden. See the class docblock. */
    public const OVERRIDES = ['AQ' => self::ANTARCTICA];

    public function __construct(
        public string $code,
        public string $name,
    ) {}

    /** @return list<string> the seven codes, alphabetically */
    public static function codes(): array
    {
        $codes = array_keys(self::M49_GROUP);
        sort($codes);

        return $codes;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name];
    }
}
