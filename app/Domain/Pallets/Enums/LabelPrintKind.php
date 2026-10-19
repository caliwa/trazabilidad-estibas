<?php

namespace App\Domain\Pallets\Enums;

enum LabelPrintKind: string
{
    case Original = 'ORIGINAL';
    case Reprint = 'REIMPRESION';
}
