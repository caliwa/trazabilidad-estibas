<?php

namespace App\Domain\Integration\Enums;

enum AttemptOutcome: string
{
    case Success = 'EXITO';
    /** SAP answered and refused the document: nothing was created. */
    case BusinessError = 'ERROR_NEGOCIO';
    /** Timeout, dropped connection or ambiguous answer: outcome unknown. */
    case Uncertain = 'INCIERTO';
    /** A read (lookup) that could not be completed. */
    case QueryFailed = 'CONSULTA_FALLIDA';
}
