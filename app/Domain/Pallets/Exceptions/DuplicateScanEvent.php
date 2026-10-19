<?php

namespace App\Domain\Pallets\Exceptions;

use RuntimeException;

/**
 * Raised inside the processing transaction when another worker stored the
 * same event first. It rolls the transaction back, including a pallet or
 * a pallet number created for it.
 *
 * @internal
 */
class DuplicateScanEvent extends RuntimeException {}
