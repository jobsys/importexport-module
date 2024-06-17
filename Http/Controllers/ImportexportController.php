<?php

namespace Modules\Importexport\Http\Controllers;


use App\Http\Controllers\BaseManagerController;
use Illuminate\Http\Request;
use Modules\Approval\Entities\ApprovalProcess;
use Modules\Approval\Enums\ApprovalStatus;
use Modules\Approval\Services\ApprovalService;
use Modules\Importexport\Entities\TransferRecord;
use Modules\Importexport\Exporters\QueryExporter;
use Modules\Importexport\Services\ImportexportService;

class ImportexportController extends BaseManagerController
{

	public function items()
	{
		$mode = request('mode');

		$is_admin = request()->user()->isSuperAdmin();

		$pagination = TransferRecord::with(['creator:id,name'])->when(!$is_admin, function ($query) {
			$query->where('user_id', request()->user()->id);
		})->when($mode, function ($query) use ($mode) {
			$query->where('type', $mode);
		})->latest()->paginate();

		$process_maps = [];
		$task_id_maps = [];

		$approval_service = app()->make(ApprovalService::class);

		foreach ($pagination->items() as &$item) {
			$ie_class = app()->make($item->class_name);

			if (!$ie_class instanceof QueryExporter) {
				continue;
			}

			$approval_type = $ie_class->getApprovalType();
			if (!$approval_type) {
				continue;
			}

			if ($item->approval_status !== ApprovalStatus::Pending) {
				continue;
			}

			if (!isset(config('approval.approval_types')[$approval_type])) {
				continue;
			}

			if (!isset($process_maps[$approval_type])) {
				$process = ApprovalProcess::where('type', config('approval.approval_types')[$approval_type]['type'])->first();
				$process_maps[$approval_type] = $process;
			} else {
				$process = $process_maps[$approval_type];
			}

			if (!isset($task_id_maps[$approval_type])) {
				$task_ids = $approval_service->getUserApprovable(TransferRecord::query(), $this->login_user, $process)->get(['id'])->pluck('id');
				$task_id_maps[$approval_type] = $task_ids;
			} else {
				$task_ids = $task_id_maps[$approval_type];
			}

			if ($task_ids->contains($item->id)) {
				$item->can_approve = true;
			}
		}


		return $this->json($pagination);

	}


	public function approveItem(ApprovalService $approvalService)
	{
		$id = request('id');

		$project = TransferRecord::find($id);
		if (!$project) {
			return $this->message('任务记录不存在');
		}

		$ie_class = app()->make($project->class_name);

		if (!$ie_class instanceof QueryExporter) {
			return $this->message('导出任务不支持审批');
		}

		$approval_type = $ie_class->getApprovalType();
		if (!$approval_type) {
			return $this->message('导出任务不支持审批');
		}

		if (!isset(config('approval.approval_types')[$approval_type])) {
			return $this->message('导出任务不支持审批');
		}

		$process = ApprovalProcess::where('type', config('approval.approval_types')[$approval_type]['type'])->first();

		$project = $approvalService->getUserApprovable(TransferRecord::where('id', $id), $this->login_user, $process, false)->first();

		if ($project) {
			$approvalService->getApprovalDetail($process, $project);

			return $this->json($project);
		}

		return $this->message('无审核权限');
	}

	public function download(ImportexportService $importexportService)
	{
		$task_id = request('task_id');

		if (request()->user()->isSuperAdmin()) {
			$record = TransferRecord::where('task_id', $task_id)->first();
		} else {
			$record = TransferRecord::where('creator_id', $this->login_user_id)->where('task_id', $task_id)->first();
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

	public function progress(Request $request, ImportexportService $service)
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
}
