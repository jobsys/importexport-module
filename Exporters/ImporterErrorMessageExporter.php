<?php

namespace Modules\Importexport\Exporters;


use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class ImporterErrorMessageExporter extends DefaultValueBinder implements FromCollection, WithHeadings, ShouldAutoSize, WithCustomValueBinder
{
	protected array $rows;
	protected array $headers;
	protected array $fields;

	public function __construct(array $rows, array $fields, array $headers)
	{
		$this->rows = $rows;
		$this->headers = ['数据异常原因（请调整后重新上传本文件）', ...$headers];
		$this->fields = $fields;
	}

	public function collection()
	{
		return collect($this->rows);
	}

	public function headings(): array
	{
		return $this->headers;
	}

	public function headingRow(): int
	{
		return 1;
	}

	/**
	 * 格式化数据
	 * @param Cell $cell
	 * @param $value
	 * @return bool
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function bindValue(Cell $cell, $value): bool
	{

		foreach ($this->fields as $index => $field) {
			if (isset($field['type']) && $field['type'] === 'string') {
				$column = Coordinate::stringFromColumnIndex($index + 2);
				if ($column === $cell->getColumn()) {
					$cell->setValueExplicit($value, DataType::TYPE_STRING);
					return true;
				}
			}
		}
		// else return default behavior
		return parent::bindValue($cell, $value);
	}
}
