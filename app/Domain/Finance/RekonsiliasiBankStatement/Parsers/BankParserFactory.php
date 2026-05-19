<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use InvalidArgumentException;

class BankParserFactory
{
    public static function make(string $bankType): BankParserInterface
    {
        $type = strtoupper($bankType);

        return match (true) {
            $type === 'BCA'                   => new BcaAdapter(),
            $type === 'BSI'                   => new BsiAdapter(),
            BankColumnMapping::has($type)     => new GenericBankParser(BankColumnMapping::get($type)),
            default                           => throw new InvalidArgumentException("Bank type tidak didukung: {$bankType}"),
        };
    }
}
