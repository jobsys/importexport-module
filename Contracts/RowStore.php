<?php

namespace Modules\Importexport\Contracts;

interface RowStore
{
    public function store(array $row, array $extra);
}
