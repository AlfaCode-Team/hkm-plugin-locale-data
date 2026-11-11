<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Infrastructure\Config;

/**
 * Two knobs, and both are normalised in the private constructor so neither
 * named constructor can bypass them.
 *
 * `env()` and not `getenv()`: `$_ENV` is the source of truth in this framework
 * and `putenv()` is never called, so `getenv()` returns null for a value that
 * is present — a default silently winning over configuration.
 */
final readonly class LocaleDataConfig
{
    /** The locale used when a caller passes null. */
    public string $defaultLocale;

    /** Seconds to cache a built list, when a CachePort is bound. 0 = no caching. */
    public int $cacheTtl;

    private function __construct(string $defaultLocale, int $cacheTtl)
    {
        $this->defaultLocale = self::locale($defaultLocale);
        $this->cacheTtl      = max(0, $cacheTtl);
    }

    public static function fromEnv(): self
    {
        return new self(
            // LOCALE_DATA_DEFAULT, then the application's own APP_LOCALE, then
            // `en`. Falling through to APP_LOCALE matters: an application that
            // has already told the framework it is French should not have to
            // say so twice for its country list to come out in French.
            defaultLocale: (string) (env('LOCALE_DATA_DEFAULT') ?: env('APP_LOCALE') ?: 'en'),
            // A day. The data changes when ICU changes, which is at the pace of
            // an OS package upgrade — so a long TTL is right, and the cache key
            // carries the ICU version so an upgrade invalidates everything
            // rather than serving last week's names forever.
            cacheTtl: (int) env('LOCALE_DATA_CACHE_TTL', 86400),
        );
    }

    public static function of(string $defaultLocale = 'en', int $cacheTtl = 86400): self
    {
        return new self($defaultLocale, $cacheTtl);
    }

    /**
     * Keep a locale tag only if it is shaped like one.
     *
     * A malformed value falls back to `en` rather than being passed to ICU.
     * ICU is tolerant — it would quietly resolve almost anything to its root
     * locale and return English — so the failure would be a country list in
     * the wrong language with nothing reporting why. Validating here makes the
     * fallback a decision rather than an accident.
     *
     * The `D` modifier because PCRE's `$` also matches immediately before a
     * final newline, so without it `"fr\n"` would pass as a tag.
     */
    private static function locale(string $given): string
    {
        $given = trim($given);

        return preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $given) === 1 ? $given : 'en';
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return [
            'default_locale' => $this->defaultLocale,
            'cache_ttl'      => $this->cacheTtl === 0 ? 'off' : $this->cacheTtl,
        ];
    }
}
