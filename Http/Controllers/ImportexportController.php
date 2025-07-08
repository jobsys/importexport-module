<?php

namespace Modules\Importexport\Http\Controllers;


use App\Http\Controllers\BaseManagerController;
use Illuminate\Http\Request;
use Modules\Approval\Services\ApprovalService;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Importexport\Services\ImportexportService;

class ImportexportController extends BaseManagerController
{

	public function items()
	{
		$mode = request('mode');

		$is_admin = request()->user()->isSuperAdmin();

		$is_super_admin = auth()->id() === config('conf.super_admin_id');

		$pagination = TransferRecord::with(['creator:id,name'])
			->where('status', '<>', TransferRecord::STATUS_PENDING)
			->when(!$is_super_admin, fn($query) => $query->where('creator_id', '<>', config('conf.super_admin_id')))
			->when(!$is_admin, fn($query) => $query->where('creator_id', request()->user()->id)
			)->when($mode, function ($query) use ($mode) {
				$query->where('type', $mode);
			})->latest()->paginate();


		$approvalService = app(ApprovalService::class);
		$importexportService = app(ImportexportService::class);

		$pagination->transform(function (TransferRecord $item) use ($approvalService, $importexportService) {
			if ($item->type === TransferRecord::TYPE_IMPORT) {
				return $item;
			}

			if ($item->status === TransferRecord::STATUS_PROCESSING) {
				$item->{'progress'} = $importexportService->getExportProgress($item->task_id);
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

	public function download(ImportexportService $importexportService)
	{
		$task_id = request('task_id');

		if (request()->user()->isSuperAdmin()) {
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

		if ($record->type === TransferRecord::TYPE_IMPORT) {
			return response()->download(storage_path('app/private/' . $record->file_path));
		}


		[$file_path, $error] = $importexportService->exportDownloadFile($task_id);

		return response()->download(storage_path('app/private/' . $file_path));
	}

	public function importProgress(Request $request, ImportexportService $service)
	{
		$ids = $request->input('ids');

		if (is_string($ids)) {
			$ids = [$ids];
		}

		$result = [];

		foreach ($ids as $id) {
			$result[] = $service->getImportProgress($id);
		}
		return $this->json($result);
	}

	public function exportProgress(Request $request, ImportexportService $service)
	{
		$ids = $request->input('ids');

		if (is_string($ids)) {
			$ids = [$ids];
		}

		$result = [];

		foreach ($ids as $id) {
			$result[] = $service->getExportProgress($id);
		}
		return $this->json($result);
	}
}
