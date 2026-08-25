<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * No tariff is registered under the given carrier/service id, or no version of it is
 * effective at the requested time / exists at the requested version number.
 */
final class TariffNotFoundException extends RatingException {}
