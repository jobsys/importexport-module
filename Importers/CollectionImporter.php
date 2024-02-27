<?php

namespace Modules\Importexport\Importers;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
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
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use Modules\ImportExport\Contracts\RowStore;
use Modules\ImportExport\Services\ImportExportService;

class CollectionImporter implements RowStore, ToCollection, WithEvents, SkipsEmptyRows, SkipsOnError, WithHeadingRow, ShouldQueue, WithChunkReading
{
    use Importable, SkipsErrors;

    protected string $id = ''; // 任务ID(UUID) 用于生成缓存的key
    protected string $task_title = ''; // 任务标题
    protected array $fields = []; // 导入字段
    protected array $headers = []; // 表头
    protected array $extra = []; // 额外的数据
    protected array $rules = []; // 验证规则
    protected array $ruleAttributes = []; // 验证规则对应的字段名称
    protected ImportExportService $service;
    protected User $imported_by;
    protected int $current_row = 1;
    protected int $error_rows = 0;

    protected string $file_path = "";

    public function __construct(string $import_id, string $task_title, array $fields, array $headers, $extra = [], User $imported_by = null, $file_path = '')
    {
        $this->id = $import_id;
        $this->task_title = $task_title;
        $this->fields = $fields;
        $this->headers = $headers;
        $this->extra = $extra;
        $this->imported_by = $imported_by;
        $this->service = new ImportExportService();
        $this->file_path = $file_path;
        $this->extractRules();

        cache()->forever("title_{$this->id}", $this->task_title);
    }

    public function collection(Collection $collection): void
    {
        foreach ($collection as $row) {
            cache()->forever("current_row_{$this->id}", $this->current_row);
            $data = $this->service->tidyImportFields($this->fields, $this->headers, $row);

            $validator = Validator::make($data, $this->rules, [], $this->ruleAttributes);
            if ($validator->fails()) {
                $this->error_rows += 1;
                cache()->forever("error_{$this->id}", true);
                cache()->forever("error_rows_{$this->id}", $this->error_rows);
                $this->service->importLog($this->id, $this->current_row, $validator->errors()->first());
                $this->current_row += 1;
                continue;
            }


            $this->store($data, $this->extra);
            $this->current_row += 1;
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
                $totalRows = $event->getReader()->getTotalRows();
                if (filled($totalRows)) {
                    cache()->forever("total_rows_{$this->id}", array_values($totalRows)[0] - 1);
                    cache()->forever("start_date_{$this->id}", now()->unix());
                }
            },
            AfterImport::class => function (AfterImport $event) {
                cache(["end_date_{$this->id}" => now()], now()->addMinute());

                if ($this->file_path) {
                    @unlink($this->file_path);
                }

                /* cache()->forget("total_rows_{$this->id}");
                 cache()->forget("start_date_{$this->id}");
                 cache()->forget("title_{$this->id}");
                 cache()->forget("error_{$this->id}"); */

            },
        ];
    }

    public function store(array $row, array $extra)
    {
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
}
