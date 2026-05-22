<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Exceptions;

use App\Models\BankStatement;

class DuplicateStatementException extends \RuntimeException
{
    public function __construct(private readonly BankStatement $statement)
    {
        parent::__construct('Rekening koran sudah diupload untuk periode ini.');
    }

    public function getStatement(): BankStatement
    {
        return $this->statement;
    }
}
