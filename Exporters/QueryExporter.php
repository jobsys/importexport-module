<?php

namespace Modules\Importexport\Exporters;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class QueryExporter implements FromQuery, WithMapping, WithHeadings
{
    use Exportable;

    public function query()
    {
        // TODO: Implement query() method.
    }

    public function map($row): array
    {
        // TODO: Implement map() method.
    }

    public function headings(): array
    {
        // TODO: Implement headings() method.
    }
}
