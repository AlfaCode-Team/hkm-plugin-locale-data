<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\LocaleData\API\Contracts\LocaleDataContract;
use Plugins\LocaleData\Domain\Continent;

/**
 * locale:data — what the installed ICU actually gives this deployment.
 *
 * It exists because the data is derived rather than shipped, which makes
 * "what will my country list look like on the production box" a real question
 * that no amount of reading this repository can answer. Run it there.
 *
 * Exits non-zero when the catalogue is obviously incomplete, so it works as a
 * deploy check on an image built with a stripped-down ICU — the failure mode
 * that otherwise shows up as a half-empty country picker.
 */
final class LocaleDataCommand extends AbstractCommand
{
    /**
     * ISO 3166-1 has 249 assigned alpha-2 codes; CLDR adds XK (Kosovo), which
     * has no ISO assignment but is in every address form. A complete ICU
     * therefore yields about 250.
     *
     * The threshold is deliberately loose — this is a check for "ICU data is
     * missing", not an assertion about a number that moves when a country is
     * admitted or CLDR reclassifies a territory.
     */
    private const MIN_COUNTRIES = 200;

    private const MIN_CURRENCIES = 100;

    private const MIN_LANGUAGES = 100;

    public function __construct(
        private readonly LocaleDataContract $locale,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'locale:data';
        $this->description = 'Show the locale reference data this ICU provides';

        $this->addOption('locale', 'l', 'Render names in this locale', acceptsValue: true);
        $this->addOption('countries', '', 'List every country');
    }

    protected function handle(): int
    {
        $locale = $this->option('locale');
        $locale = \is_string($locale) && $locale !== '' ? $locale : null;

        $countries  = $this->locale->countries($locale);
        $currencies = $this->locale->currencies($locale);
        $languages  = $this->locale->languages($locale);
        $continents = $this->locale->continents($locale);

        $this->section('Locale data');
        // Provenance and the ICU version — the data is exactly as current as
        // that, and this is the one place an operator can see which is
        // installed. Never a credential: there isn't one in this plugin.
        $this->muted($this->locale->describe());
        $this->newLine();

        $this->info(\sprintf('  countries   %d', \count($countries)));
        $this->info(\sprintf('  currencies  %d', \count($currencies)));
        $this->info(\sprintf('  languages   %d', \count($languages)));
        $this->info(\sprintf('  continents  %d', \count($continents)));
        $this->info(\sprintf('  EU members  %d', \count($this->locale->euCountries($locale))));
        $this->newLine();

        // Per continent, which is the one view that would expose a broken
        // containment walk: a continent with zero countries is the exact
        // symptom of the numeric-key bug this plugin was built around.
        $rows = [];

        foreach ($continents as $continent) {
            $rows[] = [
                $continent->code,
                $continent->name,
                (string) \count($this->locale->countriesIn($continent->code, $locale)),
            ];
        }

        $this->table()->headers(['code', 'name', 'countries'])->rows($rows)->render();

        if ((bool) $this->option('countries')) {
            $this->newLine();
            $this->table()
                ->headers(['a2', 'a3', 'num', 'name', 'cont', 'eu'])
                ->rows(array_map(static fn ($c): array => [
                    $c->alpha2, $c->alpha3, $c->numeric, $c->name, $c->continent, $c->isEu ? 'yes' : '',
                ], $countries))
                ->render();
        }

        $this->newLine();

        $short = [];

        if (\count($countries) < self::MIN_COUNTRIES) {
            $short[] = \sprintf('countries (%d, expected ~250)', \count($countries));
        }

        if (\count($currencies) < self::MIN_CURRENCIES) {
            $short[] = \sprintf('currencies (%d, expected ~153)', \count($currencies));
        }

        if (\count($languages) < self::MIN_LANGUAGES) {
            $short[] = \sprintf('languages (%d, expected ~181)', \count($languages));
        }

        foreach ($continents as $continent) {
            if ($this->locale->countriesIn($continent->code, $locale) === []) {
                $short[] = 'continent ' . $continent->code . ' has no countries';
            }
        }

        if ($short !== []) {
            $this->error(
                'This ICU is missing data: ' . implode('; ', $short) . '. A stripped-down ICU '
                . 'build is the usual cause — the extension loads, and the supplemental bundles '
                . 'are absent.',
            );

            return self::FAILURE;
        }

        $this->success(\sprintf(
            '%d countries across %d continents, %d currencies, %d languages.',
            \count($countries),
            \count(Continent::codes()),
            \count($currencies),
            \count($languages),
        ));

        return self::SUCCESS;
    }
}
