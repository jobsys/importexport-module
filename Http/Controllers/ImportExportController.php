<?php

namespace Modules\ImportExport\Http\Controllers;


use App\Http\Controllers\BaseManagerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\ImportExport\Services\ImportExportService;

class ImportExportController extends BaseManagerController
{
    public function progress(Request $request, ImportExportService $service)
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
