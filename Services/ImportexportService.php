<?php

namespace Modules\Importexport\Services;


use App\Jobs\NotifyUserOfCompletedImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\HeadingRowImport;
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

        $path = $file->storeAs('import', $this->createFilename($file),  ['disk' => 'local']);

        if (!$path) {
            return [null, '文件上传失败'];
        }

        $headings = (new HeadingRowImport)->toArray(storage_path("app" . DIRECTORY_SEPARATOR . $path));

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
            $result[$field['field']] = $row[$header] ?? '';
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
     * @param string $importer 导入器
     * @param array $fields 字段
     * @param array $extra_data 额外数据
     * @return array
     */
    public function import(string $title, string $importer, array $fields, array $extra_data = []): array
    {

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
            $file = storage_path('app/' . $path);

            $import_id = uuid_create();

            $import = new $importer($import_id, $title, $fields, $headers, $extra_data, request()->user(), $file);
            $import->import($file)->chain([
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

    public function export(string $title, string $exporter, array $fields){

    }

    public function createFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = str_replace("." . $extension, "", $file->getClientOriginalName()); // Filename without extension
        $filename = str_replace('-', '_', $filename); // Replace all dashes with underscores

        // Add timestamp hash to name of the file
        $filename .= "_" . md5(time()) . "." . $extension;

        return $filename;
    }
}
