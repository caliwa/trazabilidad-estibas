<?php

namespace App\Domain\Pallets\Exceptions;

/**
 * The box was already counted. Normal for a fixed reader, which can read
 * the same label more than once while the box goes by.
 */
class BoxAlreadyRead extends PalletRuleViolation {}
