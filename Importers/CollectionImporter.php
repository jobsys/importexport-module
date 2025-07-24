<?php

namespace Modules\Importexport\Importers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use Modules\Importexport\Contracts\RowStore;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Importexport\Services\ImportexportService;
use Modules\Importexport\Supports\ImportProgressRecorder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class CollectionImporter implements RowStore, ToCollection, WithEvents, SkipsEmptyRows, SkipsOnError, WithHeadingRow, ShouldQueue, WithChunkReading, WithMapping
{
	use Importable, SkipsErrors;

	protected TransferRecord $record;
	protected string $id = ''; // 任务ID(UUID) 用于生成缓存的key
	protected array $fields = []; // 导入字段
	protected array $headers = []; // 表头
	protected array $extra = []; // 额外的数据
	protected array $rules = []; // 验证规则
	protected string $file_path = "";
	protected array $rule_attributes = []; // 验证规则对应的字段名称
	protected ImportexportService $service;


	public function __construct(TransferRecord $record, array $fields, array $headers, $extra = [], $file_path = '')
	{
		$this->record = $record;
		$this->id = $record->task_id;
		$this->fields = $fields;
		$this->headers = $headers;
		$this->extra = $extra;
		$this->service = app(ImportexportService::class);
		$this->file_path = $file_path;
		$this->extractRules();
	}

	public function collection(Collection $collection): void
	{
		foreach ($collection as $row) {

			(new ImportProgressRecorder($this->id))->incrementProcessed();
			$data = $this->service->tidyImportFields($this->fields, $this->headers, $row);

			$serialize = land_sm3(serialize($row));
			if ((new ImportProgressRecorder($this->id))->checkDuplicateData($serialize)) {
				$content_items = $this->service->tidyImportOriginContent($this->fields, $this->headers, $data);
				(new ImportProgressRecorder($this->id))->cacheImportError($this->fields, $this->headers, $content_items, "存在重复数据。");
				continue;
			}

			$validator = Validator::make($data, $this->rules, [], $this->rule_attributes);
			if ($validator->fails()) {
				$error_message = collect($validator->errors()->messages())->flatten()->toArray();
				$content_items = $this->service->tidyImportOriginContent($this->fields, $this->headers, $data);
				(new ImportProgressRecorder($this->id))->cacheImportError($this->fields, $this->headers, $content_items, implode('', $error_message));
				continue;
			}

			[$result, $error] = $this->store($data, $this->extra);

			if ($error) {
				$content_items = $this->service->tidyImportOriginContent($this->fields, $this->headers, $data);
				(new ImportProgressRecorder($this->id))->cacheImportError($this->fields, $this->headers, $content_items, $error);
			}
		}
	}

	public function chunkSize(): int
	{
		return 1000;
	}

	public function registerEvents(): array
	{
		return [
			BeforeImport::class => function (BeforeImport $event) {
				$spreadsheet = IOFactory::load($this->file_path);
				$worksheet = $spreadsheet->getActiveSheet();
				$highest_row = $worksheet->getHighestDataRow();
				$total_rows = $highest_row - $this->headingRow();
				(new ImportProgressRecorder($this->id))->setTotal($total_rows);
				(new ImportProgressRecorder($this->id))->setStart();

				$this->record->update([
					'status' => TransferRecord::STATUS_PROCESSING,
					'started_at' => Carbon::now(),
					'total_count' => $total_rows,
				]);
			},
			AfterImport::class => function (AfterImport $event) {
				Log::info('Import done: ' . $this->id);
				$start = (new ImportProgressRecorder($this->id))->getStart();
				$error = (new ImportProgressRecorder($this->id))->getError();
				if ($error) {
					$error_file_path = (new ImportProgressRecorder($this->id))->exportImportErrors($this->record);
				}
				$this->record->update([
					'status' => TransferRecord::STATUS_DONE,
					'ended_at' => Carbon::now(),
					'error' => $error,
					'error_file_path' => $error ? $error_file_path : null,
					'duration' => gmdate('H:i:s', Carbon::createFromTimestamp($start)->diffInSeconds(Carbon::now())),
				]);
				(new ImportProgressRecorder($this->id))->refreshTTL();
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
		$rule_attributes = [];

		foreach ($this->fields as $field) {
			if (isset($field['rule'])) {
				$rules[$field['field']] = $field['rule'];
				$rule_attributes[$field['field']] = $field['label'];
			}
		}

		$this->rules = $rules;
		$this->rule_attributes = $rule_attributes;
	}

	/**
	 * @return int
	 */
	public function headingRow(): int
	{
		$is_contain_title = $this->service->isTemplateContainTitleRow($this->file_path);
		return $is_contain_title ? 2 : 1;
	}


	public function map($row): array
	{
		return array_map('trim', $row);
	}

	public function failed(Throwable $exception): void
	{
		Log::error('Import failed: ' . $this->id . ': ' . $exception->getMessage());
		$this->record->update([
			'status' => TransferRecord::STATUS_FAILED,
			'ended_at' => now(),
			'error' => $exception->getMessage(),
		]);
		(new ImportProgressRecorder($this->id))->setFailed(1);
	}
}
