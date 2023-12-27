<?php

namespace Modules\Importexport\Http\Controllers;


use App\Http\Controllers\BaseManagerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Importexport\Services\ImportexportService;

class ImportexportController extends BaseManagerController
{
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
