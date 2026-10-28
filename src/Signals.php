<?php

declare(strict_types=1);

namespace TinyTank;

use starfederation\datastar\Consts;
use Symfony\Component\HttpFoundation\Request;

/**
 * The signals a Datastar request sent, read with their type: anything a browser sends can be
 * anything, so each getter returns what you asked for, or the default.
 *
 *   PHP:  $app->signals()->int('page', 1, min: 1, max: 1000)
 *   Twig: {{ signal_int('page', 1, min: 1, max: 1000) }}, signal('q'), signal_bool('open'),
 *         signal_enum('sort', ['new', 'old'])
 *
 * Names can reach into an object: `form.email`. A body that isn't valid JSON answers 400, one larger
 * than MAX_BYTES 413.
 */
final class Signals
{
    /** Datastar sends the page's signals, which are small: more is not a browser of this site. */
    public const MAX_BYTES = 65536;

    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values = [])
    {
    }

    /**
     * Read from the `datastar` query parameter (GET, DELETE) or a JSON body (other methods). A form
     * posted by Datastar (contentType: 'form') has no signals: its fields are in the request.
     */
    public static function fromRequest(Request $request): self
    {
        $json = in_array($request->getMethod(), ['GET', 'DELETE'], true)
            ? $request->query->getString(Consts::DATASTAR_KEY)
            : ($request->getContentTypeFormat() === 'json' ? $request->getContent() : '');
        if ($json === '') {
            return new self();
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new HttpError(413, 'Request too large.');
        }
        $values = json_decode($json, true, 64);
        if (!is_array($values) || array_is_list($values) && $values !== []) {
            throw new HttpError(400, 'Bad request.');
        }

        return new self($values);
    }

    /** @return array<string, mixed> everything, as sent */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string $name): bool
    {
        return $this->value($name) !== null;
    }

    /** Text, cut to $max characters; a number becomes its text, anything else the default. */
    public function string(string $name, string $default = '', int $max = 1000): string
    {
        $value = $this->value($name);
        $value = is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : $default);

        return mb_substr($value, 0, $max);
    }

    /** A whole number (or its text, "2"), kept within $min and $max; anything else the default. */
    public function int(string $name, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = $this->value($name);
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', trim($value))) {
            $value = (int) trim($value);
        } elseif (is_float($value) && $value === floor($value) && abs($value) < 1e18) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            return $default;
        }

        return max($min ?? PHP_INT_MIN, min($max ?? PHP_INT_MAX, $value));
    }

    /** true or false (or "true", "false", 1, 0); anything else the default. */
    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->value($name);
        if (is_bool($value)) {
            return $value;
        }

        return is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default : $default;
    }

    /**
     * One of $choices; anything else the default (the first choice when not given).
     *
     * @param non-empty-list<string> $choices
     */
    public function enum(string $name, array $choices, ?string $default = null): string
    {
        $value = $this->value($name);

        return is_string($value) && in_array($value, $choices, true) ? $value : $default ?? $choices[0];
    }

    /** A value by name; `form.email` reaches into objects. */
    private function value(string $name): mixed
    {
        $value = $this->values;
        foreach (explode('.', $name) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
