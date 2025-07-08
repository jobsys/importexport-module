<?php

namespace Modules\Importexport\Exporters;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\RemembersChunkOffset;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\BeforeExport;
use Modules\Importexport\Contracts\ExportMappings;
use Modules\Importexport\Contracts\PrepareQuery;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Starter\Entities\BaseModel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class QueryExporter extends DefaultValueBinder implements FromQuery, WithHeadings, WithMapping, WithEvents, WithChunkReading, ShouldAutoSize, WithStyles, ExportMappings, PrepareQuery, WithCustomValueBinder
{
	use Exportable, RemembersChunkOffset;

	protected string $id = '';
	protected ?string $task_title = ''; // 任务标题
	protected array $extra_data = []; //传入的额外参数

	protected array $params = []; // 额外的数据
	protected array $fields = []; // 导出字段
	protected string $mode = ''; //导出模式

	protected int $total_rows = 0;

	protected int $current_chunk_row = 0;    //当前 Chunk 中的当前处理行数，非全部数据的行数， 当前总行数使用 getCurrentRow() 方法获取

	protected TransferRecord $record;

	public function __construct(string $export_id = '', ?TransferRecord $record = null, array $extra_data = [])
	{
		$this->id = $export_id;
		$this->task_title = $record?->task_name;
		$task_properties = $record?->properties ?? [];
		$this->fields = ($task_properties['approved_fields'] ?? ($task_properties['request_fields'] ?? []));
		$this->params = $task_properties['params'] ?? [];
		$this->mode = $task_properties['mode'] ?? '';
		$this->extra_data = $extra_data;
		if ($record) {
			cache()->forever("title_{$this->id}", $this->task_title);
			$this->record = $record;
		}
	}

	/**
	 * 预查询，可以在这里对查询条件进行调整然后传递给 query
	 * @param array $newbieQuery
	 * @return Builder|null
	 */
	public function prepareQuery(array $newbieQuery = []): ?Builder
	{
		return null;
	}

	public function query()
	{
		if ($this->mode === 'all') {
			return $this->prepareQuery();
		} else if ($this->mode === 'page' || $this->mode === 'selection') {
			return $this->prepareQuery()->whereIn('id', $this->params);
		} else if ($this->mode === 'query') {
			return $this->prepareQuery($this->params['newbieQuery'] ?? []);
		}
		return null;
	}

	/**
	 * 分批读取数据
	 * @return int
	 */
	public function chunkSize(): int
	{
		return 1000; // 每次最多读 1000 条，越大内存占用越高
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
		$this->current_chunk_row += 1;
		cache()->forever("current_row_{$this->id}", $this->getCurrentRow());
		if ($this->getCurrentRow() === $this->total_rows) {
			$this->record->status = TransferRecord::STATUS_DONE;
			$this->record->ended_at = now();
			$this->record->duration = gmdate('H:i:s', now()->diffInSeconds($this->record->started_at));
			$this->record->save();
			cache()->forever("end_date_{$this->id}", now()->unix());
		}
		return collect($this->headings())->map(fn($key) => $this->mappings($row)[$key] ?? null)->toArray();
	}

	/**
	 * 获取当前处理的数据的行数
	 * @return int|null
	 */
	public function getCurrentRow(): ?int
	{
		// 因为表头2行，ChunkSize 是 1000，ChunkOffset 为 3, 1003, 2003.....
		// $this->current_chunk_row 为当前 Chunk 中的当前处理行数，非全部数据的行数
		//所以用 ChunkOffset  - 2行表头 + 当前处理行数 - 1 得到全部数据的行数
		return $this->getChunkOffset() + $this->current_chunk_row;
	}


	/**
	 * 返回字段对应的数据信息
	 * 在子类中重写这个方法，返回字段对应的数据信息
	 * @param $row
	 * @return array
	 */
	public function mappings($row): array
	{
		return [];
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

	/**
	 * 导出事件
	 * @return array
	 */
	public function registerEvents(): array
	{
		return [
			BeforeExport::class => function (BeforeExport $event) {
				$total_rows = $this->query()->count();
				$this->total_rows = $total_rows;
				$this->record->status = TransferRecord::STATUS_PROCESSING;
				$this->record->total_count = $total_rows;
				$this->record->save();
				cache()->forever("total_rows_{$this->id}", $total_rows);
				cache()->forever("start_date_{$this->id}", now()->unix());
			},
		];
	}

	/**
	 * 导出失败时的处理
	 * @param Throwable $exception
	 * @return void
	 */
	public function failed(Throwable $exception): void
	{
		TransferRecord::where('task_id', $this->id)->update([
			'status' => TransferRecord::STATUS_FAILED,
		]);
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


}
