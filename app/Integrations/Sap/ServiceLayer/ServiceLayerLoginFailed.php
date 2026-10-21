<?php

namespace App\Integrations\Sap\ServiceLayer;

use RuntimeException;

/**
 * Service Layer did not open a session (wrong credentials, company or URL).
 * Nothing was sent to SAP.
 */
class ServiceLayerLoginFailed extends RuntimeException {}
