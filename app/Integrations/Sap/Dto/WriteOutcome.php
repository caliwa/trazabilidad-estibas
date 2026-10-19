<?php

namespace App\Integrations\Sap\Dto;

enum WriteOutcome: string
{
    /** SAP answered with the new document. Still to be verified before confirming. */
    case Created = 'CREATED';
    /** SAP answered and refused it: nothing was created. */
    case Rejected = 'REJECTED';
    /** No reliable answer: the document may or may not exist. */
    case Uncertain = 'UNCERTAIN';
}
