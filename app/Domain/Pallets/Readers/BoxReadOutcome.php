<?php

namespace App\Domain\Pallets\Readers;

enum BoxReadOutcome
{
    case Counted;
    case AlreadyRead;
    case Rejected;
}
