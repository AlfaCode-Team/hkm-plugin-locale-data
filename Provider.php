<?php

declare(strict_types=1);

namespace Plugins\LocaleData;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\LocaleData\API\Contracts\LocaleDataContract;
use Plugins\LocaleData\Application\Ports\LocaleCatalogue;
use Plugins\LocaleData\Application\Services\LocaleDataService;
use Plugins\LocaleData\Infrastructure\Cli\LocaleDataCommand;
use Plugins\LocaleData\Infrastructure\Config\LocaleDataConfig;
use Plugins\LocaleData\Infrastructure\Gateways\IcuLocaleCatalogue;

/**
 * LocaleData — wires the 'locale.data' domain.
 *
 * Requires no other module, and no database: there is no migration and no
 * table, because the data is read from the system's own CLDR through ICU. See
 * the README for why a shipped table would be worse.
 *
 * Pairs with `i18n.translation`, which decides WHICH locale a visitor gets;
 * this one says what things are called in it. Neither depends on the other.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'locale.data';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [LocaleDataContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            LocaleDataConfig::class,
            static fn (): LocaleDataConfig => LocaleDataConfig::fromEnv(),
        );

        $container->bindInternal(
            LocaleCatalogue::class,
            static fn (): IcuLocaleCatalogue => new IcuLocaleCatalogue(),
        );

        $container->bind(
            LocaleDataContract::class,
            static fn (ModuleContainer $c): LocaleDataService => new LocaleDataService(
                catalogue: $c->make(LocaleCatalogue::class),
                config:    $c->make(LocaleDataConfig::class),
                // Optional, and worth having: building a locale's lists costs
                // a measured 5-17 ms, paid per request by any page with a
                // country or currency picker on it. Without a CachePort the
                // service still memoizes per request, which is enough for one
                // page but not across them.
                cache:     $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // An instance, not a class-string. A class-string is built from the
        // CoreContainer, and a command whose dependencies do not resolve there
        // is SILENTLY NOT REGISTERED — it does not appear in `list` at all,
        // which looks like the plugin not being installed. This one takes
        // LocaleDataContract, an interface bound per request.
        //
        // Deferred so the ICU bundles are opened only on the CLI path: every
        // module's boot() also runs during an HTTP build.
        $cli->defer(static function (CliPipeline $cli): void {
            $cli->command(new LocaleDataCommand(self::forCli($cli->container())));
        });
    }

    private static function forCli(CoreContainer $core): LocaleDataService
    {
        return new LocaleDataService(
            catalogue: new IcuLocaleCatalogue(),
            config:    LocaleDataConfig::fromEnv(),
            // Deliberately NOT the CachePort on the CLI path. `locale:data`
            // exists to report what THIS ICU provides, and answering it from a
            // cache written by a different ICU — before an upgrade, say — would
            // make the one command that checks the data report the wrong thing.
            cache:     null,
        );
    }
}
