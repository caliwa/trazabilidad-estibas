<?php

namespace App\Integrations\Sap\Fake;

enum FakeScenario
{
    /** SAP creates the document and answers. */
    case Create;
    /** SAP creates the document and answers only its DocEntry and DocNum, without the document. */
    case CreateWithoutDocument;
    /** SAP refuses the document. */
    case Reject;
    /** SAP creates the document but the answer is lost (timeout after commit). */
    case CreateThenTimeout;
    /** The request never reaches SAP (timeout before anything is created). */
    case TimeoutWithoutCreating;
}
