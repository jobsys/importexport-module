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
use Modules\Importexport\Importers\CollectionImporter;

class ImportexportService
{


	/**
	 * 检测模板文件是否包含提示行
	 * @param $file_path
	 * @return bool
	 */
	public function isTemplateContainTitleRow($file_path): bool
	{
		//第一行标题与注意问题
		$headings = (new HeadingRowImport(1))->toArray($file_path);
		$first_cell = $headings[0][0][0] ?? null;

		if (!$first_cell) {
			return false;
		}

		if (config('conf.system_name')) {
			return Str::contains(trim($first_cell), config('conf.system_name')) || Str::contains(trim($first_cell), '导入模板');
		}
		return Str::contains(trim($first_cell), '导入模板');
	}

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

		$file_path = $this->getFileStoragePath($path);

		$is_contain_title = $this->isTemplateContainTitleRow($file_path);

		//如果包含提示行则从第二行读，否则从第一行读
		$headings = (new HeadingRowImport($is_contain_title ? 2 : 1))->toArray($file_path);

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
						$row[$header] = land_excel_date($row[$header] ?? null);
						break;
					default:
						break;
				}
			}
			$result[$field['field']] = $row[$header] ?? null;
		}
		return $result;
	}

	/*
	 * 将数据整理成导入时的原始数据格式，主要是为了生成错误信息的导出文件
	 */
	public function tidyImportOriginContent($fields, $headers, $row): array
	{
		$result = [];
		foreach ($headers as $index => $header) {

			$field = $fields[$index] ?? null;

			if (isset($field['type'])) {
				switch ($field['type']) {
					case 'date':
						$row[$field['field']] = !empty($row[$field['field']]) ? $row[$field['field']]->format('Y-m-d') : null;
						break;
					default:
						break;
				}
			}
			$result[] = $row[$field['field']] ?? null;
		}
		return $result;
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

		$title = $title . '_' . now()->format('Ymdhi') . '_' . Str::random(8);

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
			$task_id = uuid_create();
			$record = TransferRecord::create([
				'task_id' => $task_id,
				'task_name' => $title,
				'class_name' => $importer_class,
				'creator_id' => auth()->id(),
				'type' => TransferRecord::TYPE_IMPORT,
				'status' => TransferRecord::STATUS_PENDING,
				'file_path' => $path,
			]);
			/**
			 * @var CollectionImporter $importer
			 */
			$importer = new $importer_class($record, $fields, $headers, $extra_data, $storage_path);
			$importer->import($storage_path)->chain([
				new NotifyUserOfCompletedImport("{$title}完成", $task_id, auth()->user())
			]);
			return [compact('task_id'), null];
		}
		return [null, '文件上传异常'];
	}

	/**
	 * 导出数据
	 * @param string $title
	 * @param string $exporter_class
	 * @param array $extra_data
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
			return $this->exportExtractHeaders($exporter_class, $extra_data);
		}

		if (request()->exists('fields')) {
			return $this->exportCreateTask($title, $exporter_class, $extra_data);
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
	 * @param array $extra_data
	 * @return array
	 */
	public function exportExtractHeaders($exporter_class, array $extra_data = []): array
	{
		$exporter = app($exporter_class, ['task_id' => '', 'record' => null, 'extra_data' => $extra_data]);
		return [$exporter->headings(), null];
	}


	/**
	 * 创建导出任务
	 * @param $title
	 * @param $exporter_class
	 * @param array $extra_data
	 * @return array
	 */
	public function exportCreateTask($title, $exporter_class, array $extra_data = []): array
	{
		$current_user = auth()->user();
		$title = $title . '_' . now()->format('Ymdhi') . '_' . Str::random(8);


		//是否需要审核
		$should_approve = in_array(TransferRecord::class, collect(config('approval.approvables'))->firstWhere('slug', 'transfer-record')['children'] ?? []);

		$task_id = uuid_create();

		$record = TransferRecord::create([
			'task_id' => $task_id,
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
			$approvalService = app(ApprovalService::class);
			list($result, $error) = $approvalService->createApprovalTask($record);
			if ($error) {
				return [null, $error];
			}
			$record->approval_status = ApprovalStatus::Pending;
		} else {
			$record->approval_status = ApprovalStatus::Approved;
			$record->approval_comment = '无需审核';
			$record->approval_at = Carbon::now();
		}
		$record->save();

		return [['task_id' => $task_id, 'approval_status' => $record->approval_status], null];
	}


	/**
	 * 下载导出文件
	 * @param $task_id
	 * @param array $extra_data
	 * @return array
	 */
	public function exportDownloadFile($task_id, array $extra_data = []): array
	{

		set_time_limit(0);

		$current_user = auth()->user();

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

		$file_name = $this->exportCreateFileName($record->task_name);
		$file_path = "export/{$file_name}";
		$record->file_path = $file_path;
		$exporter_class = $record->class_name;

		/**
		 * @var QueryExporter $exporter
		 */
		$exporter = app($exporter_class, ['task_id' => $task_id, 'record' => $record, 'extra_data' => $extra_data]);

		$exporter->store($file_path, 'private');

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
