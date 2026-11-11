<?php

declare(strict_types=1);

namespace Plugins\LocaleData\Domain;

/**
 * One ISO 4217 currency.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `decimalDigits` IS THE FIELD THAT MATTERS, AND IT IS NOT ALWAYS 2
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Money is stored as integer minor units in this framework, and the number of
 * minor units per major unit is NOT two for every currency:
 *
 *   JPY, UGX, KRW, VND, RWF, XAF, XOF …   0 digits
 *   most of the world                     2 digits
 *   KWD, BHD, JOD, OMR, TND …             3 digits
 *
 * Hard-coding 100 is a factor-of-100 error in both directions: a 1,000 JPY
 * charge billed as 10 JPY, or a 1.000 KWD charge billed as 1,000 KWD. This
 * field comes from ICU, so it is as right as the system's CLDR and is not
 * something this plugin guessed.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `symbol` CAN BE THE CODE ITSELF, AND THAT IS CORRECT
 * ────────────────────────────────────────────────────────────────────────────
 *
 * ICU returns `CDF` as the symbol for the Congolese franc, and `$` for USD.
 * That is not a missing symbol — it is CLDR saying there is no distinct
 * narrow symbol in this locale, and the code IS what should be displayed.
 * Substituting a guess would print a symbol no local reader uses.
 *
 * The symbol is also LOCALE-DEPENDENT: ICU gives `$` for USD in `en` and
 * `$US` in `fr`. Both are right for their reader, which is why the symbol is
 * resolved per requested locale rather than stored once.
 */
final readonly class Currency
{
    public function __construct(
        /** ISO 4217 alpha-3, upper case. */
        public string $code,
        /**
         * ISO 4217 numeric, as a three-character string. Empty when CLDR has
         * no numeric code for it — which happens for the supranational codes.
         * A string for the same reason as {@see Country::$numeric}.
         */
        public string $numeric,
        /** Localized symbol, which may legitimately BE the code. */
        public string $symbol,
        /** Localized display name. */
        public string $name,
        /** Minor units per major unit, as a power of ten. 0, 2 or 3. */
        public int $decimalDigits,
    ) {}

    /** The integer divisor for converting minor units to major units. */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->decimalDigits;
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'code'           => $this->code,
            'numeric'        => $this->numeric,
            'symbol'         => $this->symbol,
            'name'           => $this->name,
            'decimal_digits' => $this->decimalDigits,
        ];
    }
}
