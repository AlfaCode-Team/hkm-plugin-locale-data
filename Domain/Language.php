<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Domain;

/**
 * One ISO 639-1 language.
 *
 * ISO 639-1 (two letters) and not 639-2/639-3, because this list exists to
 * populate a language picker and 639-3 has over seven thousand entries — a
 * picker nobody can use. The two-letter set is the ~180 languages with enough
 * written presence to have been assigned one.
 *
 * `nativeName` is the language's name IN ITSELF — "Deutsch", not "German" —
 * which is what a language picker must show. A user who cannot read the
 * current interface language cannot read "German" either, so a list localized
 * only into the active locale is a list that fails exactly the person using it.
 */
final readonly class Language
{
    public function __construct(
        /** ISO 639-1, lower case. */
        public string $code,
        /** Name in the locale that was asked for: "German". */
        public string $name,
        /** Name in the language itself: "Deutsch". */
        public string $nativeName,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'code'        => $this->code,
            'name'        => $this->name,
            'native_name' => $this->nativeName,
        ];
    }
}
