<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Infrastructure\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/**
 * `GatewayException` (HTTP 502), because every one of these is the data SOURCE
 * failing rather than a caller asking for something wrong.
 *
 * Nothing a caller passes can raise one: an unknown country code returns null,
 * and an unknown locale falls back to the default. A missing extension or an
 * ICU build without the supplemental bundle is an environment problem, and 502
 * says that rather than blaming the request.
 */
final class LocaleDataException extends GatewayException
{
    public static function intlMissing(): self
    {
        return new self(
            'The `locale.data` catalogue needs the `intl` extension, which is not loaded. '
            . 'This plugin derives its country, currency and language data from the system\'s own '
            . 'CLDR through ICU rather than shipping a table of its own — see the README for why '
            . '— so there is no fallback path. Install php-intl (Debian/Ubuntu: `apt install '
            . 'php-intl`; macOS Homebrew: bundled; Alpine: `apk add php-intl`).',
            layer: 'gateway.locale-data.boot',
        );
    }

    /**
     * An ICU build whose supplemental data is absent or unreadable.
     *
     * Rare, and worth its own message: it happens on a stripped-down ICU built
     * without the full data bundle, where `extension_loaded('intl')` is still
     * true. The symptom without this would be an empty country list, which
     * reads as a bug in this plugin.
     */
    public static function bundleMissing(string $path): self
    {
        return new self(
            "ICU has no readable resource bundle at [{$path}]. The `intl` extension is loaded, so "
            . 'this is an ICU built without its full data bundle rather than a missing extension — '
            . 'the symptom is otherwise an empty country list that looks like a bug here.',
            layer: 'gateway.locale-data.bundle',
            context: ['bundle' => $path, 'icu' => INTL_ICU_VERSION],
        );
    }

    /**
     * A country that could not be placed on a continent.
     *
     * Throws rather than filing it under `''` or `'XX'`. A country silently
     * given a placeholder continent is exactly the invisible-wrong-reference-
     * data failure this plugin is built to avoid: nothing breaks, and a picker
     * grouped by continent quietly loses a country.
     */
    public static function noContinent(string $alpha2): self
    {
        return new self(
            "Country [{$alpha2}] could not be placed on a continent by walking ICU's M49 "
            . 'territory containment tree. Every ISO 3166-1 country resolves on a complete ICU, '
            . 'so this indicates an unexpected containment shape in this build.',
            layer: 'gateway.locale-data.continent',
            context: ['country' => $alpha2, 'icu' => INTL_ICU_VERSION],
        );
    }
}
