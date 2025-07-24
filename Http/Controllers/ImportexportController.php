<?php

namespace Modules\Importexport\Http\Controllers;


use App\Http\Controllers\BaseManagerController;
use Illuminate\Http\Request;
use Modules\Approval\Services\ApprovalService;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Importexport\Services\ImportexportService;
use Modules\Importexport\Supports\ExportProgressRecorder;
use Modules\Importexport\Supports\ImportProgressRecorder;

class ImportexportController extends BaseManagerController
{

	public function items()
	{
		$mode = request('mode');

		$is_admin = auth()->user()->isSuperAdmin();

		$is_super_admin = auth()->id() === config('conf.super_admin_id');

		$pagination = TransferRecord::with(['creator:id,name'])
			->where('status', '<>', TransferRecord::STATUS_PENDING)
			->when(!$is_super_admin, fn($query) => $query->where('creator_id', '<>', config('conf.super_admin_id')))
			->when(!$is_admin, fn($query) => $query->where('creator_id', auth()->user()->id)
			)->when($mode, function ($query) use ($mode) {
				$query->where('type', $mode);
			})->latest()->paginate();


		$approvalService = app(ApprovalService::class);

		$pagination->transform(function (TransferRecord $item) use ($approvalService) {
			if ($item->status !== TransferRecord::STATUS_DONE && $item->status !== TransferRecord::STATUS_FAILED) {
				if ($item->type === TransferRecord::TYPE_IMPORT) {
					$item->{'progress'} = (new ImportProgressRecorder($item->task_id))->getProgress();
				} else {
					$item->{'progress'} = (new ExportProgressRecorder($item->task_id))->getProgress();
				}
			}

			if (!in_array(TransferRecord::class, collect(config('approval.approvables'))->firstWhere('slug', 'transfer-record')['children'] ?? [])) {
				return $item;
			}

			[, $error] = $approvalService->canUserApproveTarget($item);

			if (!$error) {
				$item->{'can_approve'} = true;
			}

			return $item;
		});

		return $this->json($pagination);
	}


	public function approveItem(ApprovalService $approvalService)
	{
		$id = request('id');

		$record = TransferRecord::find($id);
		if (!$record) {
			return $this->message('任务记录不存在');
		}

		if ($record->type !== TransferRecord::TYPE_IMPORT) {
			return $this->message('导入任务不支持审核');
		}

		[, $error] = $approvalService->canUserApproveTarget($record);

		if (!$error) {
			$record->loadApprovalDetail();
			return $this->json($record);
		}

		return $this->message('无审核权限');
	}

	public function download(ImportexportService $service)
	{
		$task_id = request('task_id');

		if (auth()->user()->isSuperAdmin()) {
			$record = TransferRecord::where('task_id', $task_id)->first();
		} else {
			$record = TransferRecord::where('creator_id', auth()->id())->where('task_id', $task_id)->first();
		}

		if (!$record) {
			return $this->message('任务记录不存在');
		}

		if (!$record->file_path && $record->type === TransferRecord::TYPE_IMPORT) {
			return $this->message('文件不存在');
		}

		return response()->download($service->getFileStoragePath($record->file_path));
	}


	public function downloadErrorFile(ImportexportService $service)
	{
		$task_id = request('task_id');

		if (auth()->user()->isSuperAdmin()) {
			$record = TransferRecord::where('task_id', $task_id)->first();
		} else {
			$record = TransferRecord::where('creator_id', auth()->id())->where('task_id', $task_id)->first();
		}

		if (!$record) {
			return $this->message('任务记录不存在');
		}

		if (!$record->error_file_path) {
			return $this->message('无错误文件');
		}

		return response()->download($service->getFileStoragePath($record->error_file_path));

	}


	public function importProgress(Request $request)
	{
		$ids = $request->input('ids');

		if (is_string($ids)) {
			$ids = [$ids];
		}

		$result = [];

		foreach ($ids as $id) {
			$result[] = (new ImportProgressRecorder($id))->getProgress();
		}
		return $this->json($result);
	}

	public function exportProgress(Request $request)
	{
		$ids = $request->input('ids');

		if (is_string($ids)) {
			$ids = [$ids];
		}

		$result = [];

		foreach ($ids as $id) {
			$result[] = (new ExportProgressRecorder($id))->getProgress();
		}
		return $this->json($result);
	}
}
