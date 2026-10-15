<?php

declare(strict_types=1);

namespace TinyTank\Content;

/** A date in YAML that isn't a real day in YYYY-MM-DD form (see StrictYaml). */
final class InvalidDate extends \RuntimeException
{
}
