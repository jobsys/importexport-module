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

        $pagination = TransferRecord::with(['creator:id,name'])->when(!$is_admin, function ($query) {
            $query->where('user_id', request()->user()->id);
        })->when($mode, function ($query) use ($mode) {
            $query->where('type', $mode);
        })->latest()->paginate();


        $approvalService = app()->make(ApprovalService::class);

        foreach ($pagination->items() as $item) {

            if ($item->type === TransferRecord::TYPE_IMPORT) {
                continue;
            }

            if (!in_array(TransferRecord::class, collect(config('approval.approvables'))->firstWhere('slug', 'transfer-record')['children'] ?? [])) {
                continue;
            }

            [, $error] = $approvalService->canUserApproveTarget($item);

            if (!$error) {
                $item->can_approve = true;
            }
        }

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
