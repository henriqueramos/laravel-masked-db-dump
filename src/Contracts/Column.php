<?php

declare(strict_types=1);

namespace RamosHenrique\LaravelMaskedDumper\Contracts;

interface Column
{
    public function modifyValue($value);
}
