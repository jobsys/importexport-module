<?php

namespace Modules\Importexport\Exporters;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Importexport\Contracts\ExportMappings;
use Modules\Importexport\Contracts\PrepareQuery;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Starter\Entities\BaseModel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class QueryExporter extends DefaultValueBinder implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, ExportMappings, PrepareQuery, WithCustomValueBinder
{
	use Exportable;

	protected string $id = '';
	protected ?string $task_title = ''; // 任务标题
	protected array $extra_data = []; //传入的额外参数

	protected array $params = []; // 额外的数据
	protected array $fields = []; // 导出字段
	protected string $mode = ''; //导出模式

	protected int $index = 0; //当前行号

	public function __construct(string $export_id = '', ?TransferRecord $record = null, array $extra_data = [])
	{
		$this->id = $export_id;
		$this->task_title = $record?->task_name;
		$task_properties = $record?->properties ?? [];
		$this->fields = ($task_properties['approved_fields'] ?? ($task_properties['request_fields'] ?? []));
		$this->params = $task_properties['params'] ?? [];
		$this->mode = $task_properties['mode'] ?? '';
		$this->extra_data = $extra_data;
	}

	/**
	 * 预查询，可以在这里对查询条件进行调整然后传递给 query
	 * @return Builder|null
	 */
	public function prepareQuery(): ?Builder
	{
		return null;
	}

	public function query()
	{
		$query = $this->prepareQuery();
		if ($this->mode === 'all') {
			return $query;
		} else if ($this->mode === 'page' || $this->mode === 'selection') {
			return $query->whereIn('id', $this->params);
		} else if ($this->mode === 'query') {
			return $query->filterable([], [], $this->params['newbieQuery'] ?? []);
		}
		return null;
	}

	/**
	 * 表头
	 * @return array
	 */
	public function headings(): array
	{
		return filled($this->fields) ? $this->fields : array_keys($this->mappings(new BaseModel()));
	}

	/**
	 * 写数据
	 */
	public function map($row): array
	{
		$this->index += 1;
		return collect($this->headings())->map(fn($key) => $this->mappings($row)[$key] ?? null)->toArray();
	}


	/**
	 * 返回字段对应的数据信息
	 * @param $row
	 * @return array
	 */
	public function mappings($row): array
	{
		return [];
	}

	/**
	 * 表格样式
	 * @param Worksheet $sheet
	 * @return array[]
	 */
	public function styles(Worksheet $sheet): array
	{
		return [
			1 => [
				'font' => [
					'bold' => true,
					'size' => 12
				],
			],
		];
	}

	public function bindValue(Cell $cell, $value): bool
	{

		$mapping = method_exists($this, 'formats') ? $this->formats() : [];

		$headings = collect($this->headings());
		foreach ($mapping as $key => $format) {
			$column = Coordinate::stringFromColumnIndex($headings->search($key) + 1);
			if ($column === $cell->getColumn()) {
				$cell->setValueExplicit($value, $format);
				return true;
			}
		}
		// else return default behavior
		return parent::bindValue($cell, $value);
	}


}
