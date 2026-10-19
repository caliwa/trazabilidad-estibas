<?php

namespace App\Integrations\Sap\Exceptions;

use RuntimeException;

/**
 * SAP could not be asked, or did not answer in a way that proves anything.
 * Callers must treat the document's existence as unknown.
 */
class SapLookupFailed extends RuntimeException {}
