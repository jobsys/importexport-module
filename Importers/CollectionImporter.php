<?php

namespace Modules\Importexport\Importers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\RemembersChunkOffset;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeSheet;
use Modules\Importexport\Contracts\RowStore;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Importexport\Services\ImportexportService;

class CollectionImporter implements RowStore, ToCollection, WithEvents, SkipsEmptyRows, SkipsOnError, WithHeadingRow, ShouldQueue, WithChunkReading, WithMapping
{
	use Importable, SkipsErrors, RemembersChunkOffset;

	protected string $id = ''; // 任务ID(UUID) 用于生成缓存的key
	protected string $task_title = ''; // 任务标题
	protected array $fields = []; // 导入字段
	protected array $headers = []; // 表头
	protected array $extra = []; // 额外的数据
	protected array $rules = []; // 验证规则
	protected array $ruleAttributes = []; // 验证规则对应的字段名称
	protected ImportexportService $service;
	protected int $current_chunk_row = 1; //当前 Chunk 中的当前处理行数，非全部数据的行数
	protected int $error_rows = 0;

	protected string $file_path = "";

	public function __construct(string $import_id, string $task_title, array $fields, array $headers, $extra = [], $file_path = '')
	{
		$this->id = $import_id;
		$this->task_title = $task_title;
		$this->fields = $fields;
		$this->headers = $headers;
		$this->extra = $extra;
		$this->service = new ImportexportService();
		$this->file_path = $file_path;
		$this->extractRules();

		cache()->forever("title_{$this->id}", $this->task_title);
	}

	public function collection(Collection $collection): void
	{


		foreach ($collection as $row) {
			$current_row = $this->getCurrentRow();
			$current_display_row = $this->getDisplayCurrentRow();
			cache()->forever("current_row_{$this->id}", $current_row);
			$data = $this->service->tidyImportFields($this->fields, $this->headers, $row);

			$validator = Validator::make($data, $this->rules, [], $this->ruleAttributes);
			if ($validator->fails()) {
				$this->error_rows += 1;
				cache()->forever("error_{$this->id}", true);
				cache()->forever("error_rows_{$this->id}", $this->error_rows);
				$this->service->importLog($this->id, $current_display_row, $validator->errors()->first());
				$this->current_chunk_row += 1;
				continue;
			}

			[$result, $error] = $this->store($data, $this->extra);

			if ($error) {
				$this->error_rows += 1;
				cache()->forever("error_{$this->id}", true);
				cache()->forever("error_rows_{$this->id}", $this->error_rows);
				$this->service->importLog($this->id, $current_display_row, $error);
			}

			$this->current_chunk_row += 1;
		}
	}

	public function chunkSize(): int
	{
		return 1000;
	}

	public function registerEvents(): array
	{
		return [
			BeforeSheet::class => function (BeforeSheet $event) {
				$worksheet = $event->getSheet();
				$highest_row = $worksheet->getHighestRow();
				$non_empty_rows = 0;
				for ($row = 0; $row <= $highest_row; $row++) {
					$value = $worksheet->getCell("A{$row}")->getValue();
					if (!is_null($value) && trim((string)$value) !== '') {
						$non_empty_rows++;
					}
				}

				cache()->forever("total_rows_{$this->id}", $non_empty_rows - $this->headingRow());
				cache()->forever("start_date_{$this->id}", now()->unix());

				TransferRecord::where('task_id', $this->id)->update([
					'status' => TransferRecord::STATUS_PROCESSING,
					'started_at' => Carbon::now(),
				]);
			},
			AfterImport::class => function (AfterImport $event) {
				cache()->forever("end_date_$this->id", now()->unix());

				TransferRecord::where('task_id', $this->id)->update([
					'status' => TransferRecord::STATUS_DONE,
					'ended_at' => Carbon::now(),
					'total_count' => cache("total_rows_$this->id") ?? 0,
					'error_file_path' => filled(cache("error_$this->id")) ? "import/$this->id.csv" : null,
					'duration' => gmdate('H:i:s', Carbon::createFromTimestamp(cache("start_date_$this->id"))->diffInSeconds(Carbon::now())),
				]);
			},
		];
	}

	/**
	 * 第一个返回值为是否成功，第二个为错误信息
	 * @param array $row
	 * @param array $extra
	 * @return array
	 */
	public function store(array $row, array $extra): array
	{

		return [true, null];
	}

	/**
	 * 抽取出字段的验证规则
	 * @return void
	 */
	private function extractRules(): void
	{
		$rules = [];
		$ruleAttributes = [];

		foreach ($this->fields as $field) {
			if (isset($field['rule'])) {
				$rules[$field['field']] = $field['rule'];
				$ruleAttributes[$field['field']] = $field['label'];
			}
		}

		$this->rules = $rules;
		$this->ruleAttributes = $ruleAttributes;
	}

	/**
	 * @return int
	 */
	public function headingRow(): int
	{
		return 2;
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
		return $this->getChunkOffset() - $this->headingRow() + $this->current_chunk_row - 1;
	}

	/**
	 * 获取当前处理的数据的行数，用于显示在前端
	 * @return int|null
	 */
	public function getDisplayCurrentRow(): ?int
	{
		return $this->getCurrentRow() + $this->headingRow();
	}

	public function map($row): array
	{
		return array_map('trim', $row);
	}
}
