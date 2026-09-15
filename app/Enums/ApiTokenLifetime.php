<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * How long a newly issued personal access token stays valid.
 *
 * A fixed set rather than a free number of days. The control is then a select
 * with nothing to mistype, and the stored value is one of a handful the
 * interface can name back — "expires in 3 months" rather than a date the user
 * has to work out they chose.
 *
 * `Never` stays available because a self-hosted installation may genuinely
 * want a token that outlives anyone's attention, such as a scanner dropping
 * files in unattended. What changes is that it has to be chosen: before this
 * enum existed every token was `Never` by default, which is the setting
 * nobody picks deliberately and everybody ends up with.
 */
enum ApiTokenLifetime: string
{
    case ThirtyDays = '30';

    case SixtyDays = '60';

    case NinetyDays = '90';

    case OneYear = '365';

    case Never = 'never';

    /**
     * What a token gets when the request does not choose one.
     *
     * Long enough not to interrupt a working script every other week, short
     * enough that a token leaked into a dotfile stops being a key to the
     * archive within a quarter.
     */
    public const DEFAULT = self::NinetyDays;

    /**
     * Resolve a submitted value, falling back to the default.
     *
     * Deliberately forgiving: a form posted without the field — an older tab,
     * a client that does not know about it — gets the default lifetime rather
     * than a validation error or, worse, a token that never expires.
     *
     * @param string|null $value The submitted value, if any.
     *
     * @return self The matching case, or DEFAULT when $value is null or unknown.
     */
    public static function fromRequestValue(?string $value): self
    {
        return $value === null ? self::DEFAULT : (self::tryFrom($value) ?? self::DEFAULT);
    }

    /**
     * The moment a token issued now under this lifetime stops being accepted.
     *
     * @return Carbon|null The expiry instant, or null for a token that does not expire.
     */
    public function expiresAt(): ?Carbon
    {
        return $this === self::Never ? null : Carbon::now()->addDays((int) $this->value);
    }
}
