<?php

namespace Modules\ImportExport\Contracts;

interface RowStore
{
    public function store(array $row, array $extra);
}
