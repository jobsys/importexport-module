<?php

namespace Modules\Importexport\Services;


use App\Jobs\NotifyUserOfCompletedImport;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\HeadingRowImport;
use Modules\Approval\Enums\ApprovalStatus;
use Modules\Approval\Services\ApprovalService;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Importexport\Exporters\QueryExporter;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ImportexportService
{
	/**
	 * 保存文件并读取文件头
	 * @param UploadedFile $file
	 * @return array
	 */
	public function readHeaders(UploadedFile $file): array
	{

		$path = $file->storeAs('import', $this->createFilename($file), ['disk' => 'private']);

		if (!$path) {
			return [null, '文件上传失败'];
		}


		//第二行为表头，第一行为标题与注意问题
		$headings = (new HeadingRowImport(2))->toArray($this->getFileStoragePath($path));

		return [['path' => $path, 'headers' => $headings[0][0] ?? []], null];
	}

	/**
	 * 为前端组件生成对应数据格式
	 * @param array $fields
	 * @return array
	 */
	public function combineFieldsAndRules(array $fields): array
	{
		$result = [];
		foreach ($fields as $field) {
			$result[] = [$field['label'], isset($field['rule']) && Str::contains($field['rule'], 'required')];
		}
		return $result;
	}

	/**
	 * 整理导入的字段
	 * @param $fields
	 * @param $headers
	 * @param $row
	 * @return array
	 */
	public function tidyImportFields($fields, $headers, $row): array
	{
		$result = [];
		foreach ($fields as $index => $field) {
			$header = $headers[$index] ?? null;
			if (!$header) continue;

			if (isset($field['type'])) {
				switch ($field['type']) {
					case 'date':
						$row[$header] = Date::excelToDateTimeObject($row[$header] ?? '');
						break;
					default:
						break;
				}
			}
			$result[$field['field']] = $row[$header] ?? null;
		}
		return $result;
	}

	/**
	 * 记录导入错误日志
	 * @param $import_id
	 * @param $row
	 * @param $message
	 * @return void
	 */
	public function importLog($import_id, $row, $message): void
	{
		$dir_path = storage_path("app/public/import");

		if (!is_dir($dir_path)) {
			mkdir($dir_path);
		}
		$path = "import/{$import_id}.csv";

		$file_path = storage_path("app/public/{$path}");
		$file = fopen($file_path, "a+");
		fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
		if (!Storage::exists($path)) {
			$header = ['行数', '错误信息'];
			@fputcsv($file, $header);
		}
		$content = ["第{$row}行", $message];
		@fputcsv($file, $content);
		@fclose($file);
	}

	/**
	 * 导入数据，如果上传数据中有文件，将文件保存到本地，并返回文件路径与表头，如果不含文件而只有文件路径，则进行数据导入
	 * @param string $title 标题
	 * @param string $importer_class 导入器
	 * @param array $fields 字段
	 * @param array $extra_data 额外数据
	 * @return array
	 */
	public function import(string $title, string $importer_class, array $fields, array $extra_data = []): array
	{

		$title = $title . '_' . now()->format('Y_m_d');

		$file = request()->file('file');
		$path = request()->input('path');
		$headers = request()->input('headers');

		if ($file) {
			list($result, $error) = $this->readHeaders($file);
			if ($error) {
				return [null, $error];
			}
			return [
				array_merge($result, ['fields' => $this->combineFieldsAndRules($fields)]),
				null
			];
		} else if ($path) {
			$storage_path = $this->getFileStoragePath($path);
			$import_id = uuid_create();
			TransferRecord::create([
				'task_id' => $import_id,
				'task_name' => $title,
				'class_name' => $importer_class,
				'creator_id' => request()->user()->id,
				'type' => TransferRecord::TYPE_IMPORT,
				'status' => TransferRecord::STATUS_PENDING,
				'file_path' => $path,
			]);
			$importer = new $importer_class($import_id, $title, $fields, $headers, $extra_data, request()->user(), $storage_path);
			$importer->import($storage_path)->chain([
				new NotifyUserOfCompletedImport("{$title}完成", $import_id, request()->user())
			]);
			return [compact('import_id'), null];
		}
		return [null, '文件上传异常'];
	}

	/**
	 * 获取导入进度
	 * @param $id
	 * @return array
	 */
	public function getImportProgress($id): array
	{
		return [
			'title' => cache("title_$id"),
			'started' => cache("start_date_$id"),
			'finished' => cache("end_date_$id"),
			'current_row' => (int)cache("current_row_$id"),
			'total_rows' => (int)cache("total_rows_$id"),
			'error' => filled(cache("error_$id")) ? Storage::url("import/$id.csv") : null,
			'error_rows' => (int)cache("error_rows_$id"),
		];
	}

	/**
	 * 导出数据
	 * @param string $title
	 * @param string $exporter_class
	 * @return array
	 */
	public function export(string $title, string $exporter_class, array $extra_data = []): array
	{
		/**
		 * 导出分成三步走，都是同一个 API
		 * 第一步：无特殊参数，返回 exporter 中的 headers，页面进行字段定制
		 * 第二步：提交申请，带上 fields，生成导出任务，返回任务 ID
		 * 第三步：审核通过正式导出并生成下载链接
		 *
		 * @var Exportable $exporter_class
		 * @var QueryExporter $exporter
		 */

		if (!request('fields', false) && !request('task_id', false)) {
			return $this->exportExtractHeaders($exporter_class);
		}

		if (request()->exists('fields')) {
			$title = $title . '_' . now()->format('Y_m_d');;
			return $this->exportCreateTask($title, $exporter_class);
		}

		if ($task_id = request('task_id')) {
			[$file_path, $error] = $this->exportDownloadFile($task_id, $extra_data);

			if ($error) {
				return [null, $error];
			}
			return [Storage::disk('private')->temporaryUrl($file_path, now()->addMinutes(30)), null];
		}

		return [null, '导出任务不存在'];
	}

	/**
	 * 根据 Exporter 提取 Headers
	 * @param $exporter_class
	 * @return array
	 */
	public function exportExtractHeaders($exporter_class): array
	{
		$exporter = app()->make($exporter_class);
		return [$exporter->headings(), null];
	}


	/**
	 * 创建导出任务
	 * @param $title
	 * @param $exporter_class
	 * @return array
	 */
	public function exportCreateTask($title, $exporter_class): array
	{
		$current_user = request()->user();
		$title = $title . '_' . now()->format('Y_m_d');;


		$exporter = app()->make($exporter_class);
		$approval_type = $exporter->getApprovalType();

		//是否需要审核
		$should_approve = true;
		if (!$approval_type || !isset(config('approval.approval_types')[$approval_type]) || $current_user->isSuperAdmin()) {
			$should_approve = false;
		}

		$export_id = uuid_create();

		$record = TransferRecord::create([
			'task_id' => $export_id,
			'task_name' => $title,
			'creator_id' => $current_user->id,
			'class_name' => $exporter_class,
			'type' => TransferRecord::TYPE_EXPORT,
			'status' => TransferRecord::STATUS_PENDING,
			'properties' => [
				'mode' => request('mode', ''),
				'request_fields' => request('fields'),
				'params' => request('params')
			],
		]);

		if ($should_approve) {
			$approvalService = new ApprovalService();
			list($result, $error) = $approvalService->createApprovalTask($record, config('approval.approval_types')[$approval_type]);
			if ($error) {
				return [null, $error];
			}
			$record->approval_status = ApprovalStatus::Pending;
			$record->save();
		} else {
			$record->approval_status = ApprovalStatus::Approved;
			$record->approval_comment = '无需审核';
			$record->approval_at = Carbon::now();
			$record->save();
		}

		return [['export_id' => $export_id, 'approval_status' => $record->approval_status], null];
	}


	/**
	 * 下载导出文件
	 * @param $task_id
	 * @param array $extra_data
	 * @return array
	 */
	public function exportDownloadFile($task_id, array $extra_data = []): array
	{

		$current_user = request()->user();

		$record = TransferRecord::where('task_id', $task_id)->first();

		if (!$record) {
			return [null, '导出任务不存在'];
		}

		if ($record->approval_status !== ApprovalStatus::Approved && !$current_user->isSuperAdmin()) {
			return [null, '导出任务未审核通过'];
		}

		if ($record->file_path) {
			return [$record->file_path, null];
		}

		$record->started_at = now();

		$exporter_class = $record->class_name;

		$exporter = new $exporter_class($task_id, $record, $extra_data);

		$file_name = $this->exportCreateFileName($record->task_name);

		$file_path = "export/{$file_name}";

		$store_result = $exporter->store($file_path, 'private');

		if ($store_result) {
			$record->status = TransferRecord::STATUS_DONE;
			$record->file_path = $file_path;
			$record->ended_at = now();
			$record->duration = gmdate('H:i:s', now()->diffInSeconds($record->started_at));
			$record->save();
		} else {
			return [null, '导出失败'];
		}

		return [$file_path, null];
	}

	public function createFilename(UploadedFile $file): string
	{
		$extension = $file->getClientOriginalExtension();
		$filename = str_replace("." . $extension, "", $file->getClientOriginalName()); // Filename without extension
		$filename = str_replace('-', '_', $filename); // Replace all dashes with underscores

		// Add timestamp hash to name of the file
		$filename .= "_" . time() . '_' . Str::random(6) . "." . $extension;

		return $filename;
	}

	public function exportCreateFileName($task_name): string
	{
		return $task_name . '_' . time() . '_' . Str::random(6) . '.xlsx';
	}

	/**
	 * 获取文件存储路径
	 * @param $file_path
	 * @return string
	 */
	public function getFileStoragePath($file_path): string
	{
		return config('filesystems.disks.private.root') . DIRECTORY_SEPARATOR . $file_path;
	}
}
