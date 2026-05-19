<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use InvalidArgumentException;

class BankParserFactory
{
    public static function make(string $bankType): BankParserInterface
    {
        return match (strtoupper($bankType)) {
            'BCA'     => new BcaParser(),
            'MANDIRI' => new MandiriParser(),
            'BNI'     => new BniParser(),
            'BRI'     => new BriParser(),
            default   => throw new InvalidArgumentException("Bank type tidak didukung: {$bankType}"),
        };
    }
}
