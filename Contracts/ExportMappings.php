<?php

namespace Modules\Importexport\Contracts;

interface ExportMappings
{
    public function mappings($row):array;
}
