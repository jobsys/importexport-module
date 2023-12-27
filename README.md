# **Importexport** 导入导出模块
该模板主要功能是基于 Excel 进行数据的导入导出。

!> 该模板的`数据导入`功能为异步执行，依赖于 [Laravel 队列](https://laravel.com/docs/10.x/queues)，请确保后台已经正确开起了队列命令。
```bash
# 该命令需长驻后台，可使用 supervisor 进行管理，在导入逻辑有变化后，需要重启该命令
php artisan queue:work 
```

## 模块安装

```bash
composer require jobsys/importexport-module
```

### 依赖

+ PHP 依赖

   ```json5
   {
       "maatwebsite/excel": "^3.1",            // Excel 操作库
   }
   ```
+ JS 依赖 (无)

### 配置

#### 模块配置 `config/module.php`

```php
"Importexport" => [
     "route_prefix" => "manager",                                                   // 路由前缀
 ]
```

#### `maatwebsite/excel` 配置

```bash
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

> 具体配置查看 [Laravel Excel](https://docs.laravel-excel.com/3.1/getting-started/)



## 模块功能

### 数据导入

数据导入由 `NewbieImporter` 组件负责前端的上传、字段对应、进度显示等功能，并结合 `ImportexportService` 提供的 API 进行数据的导入。


#### 开发规范

1. 在 `Controller` 中定义本次导入的字段，每个字段有 4 个属性分别为 `field` 接收并存储的字段名，`label` 字段的名称，`rule` 字段的验证规则。

   | 属性 | 类型 | 说明                                                                                                            |
       | --- | --- |---------------------------------------------------------------------------------------------------------------|
   | field | string | 接收并存储的字段名                                                                                                     |
   | label | string | 字段的名称                                                                                                         |
   | rule | string | 字段的验证规则，规则与 [Laravel Form Validation](https://laravel.com/docs/10.x/validation#available-validation-rules) 一致 |
   | type | string | 字段的类型，如 `date`，导入时会自动对该列数据进行处理                                                                                |

    ```php
    // 定义导入字段
    $fields = [
        ['field' => 'name', 'label' => '学员姓名', 'rule' => 'required'],
        ['field' => 'student_num', 'label' => '学员编号'],
        ['field' => 'birthday', 'label' => '生日', 'type' => 'date'],
    ];
   
    // 附属数据，将在后续的 Importer 中一并处理
    $extra_data = ['creator_id' => $this->login_user_id];
   
    // 调用 ImportexportService 的 import 方法进行数据导入
    list($result, $error) = $service->import('学员信息导入', StudentImporter::class, $fields, $extra_data);
 
    ```

2. 在页面引入 `NewbieImporter` 组件，其中 `url` 属性为上一步中的 API，`progress-url` 由本模块提供的默认路由，`extra-data` 可以定义一些其它的附属属性，并可以将其与上传的数据一并发送到 Controller。

   > 组件属性 `extra-data` 与上一步中的 `extra_data` 不是同一个属性，组件中的 `$extra-data` 仅会在上传时一并发送到 Controller，而上一步中的 `$extra_data` 会在 Importer 的 `store` 方法中一并处理。

    ```js
    import NewbieImporter from "@modules/Importexport/Resources/views/web/components/NewbieImporter.vue"
    ```

    ```html
   
    <a-button type="parimary" @click="() => studentImporter.openImporter()">导入数据</a-button>
   
    <NewbieImporter
			ref="studentImporter"
			:url="route('api.manager.course.student.import')"
			template-url="/templates/student-import-template.xlsx"
			:progress-url="route('api.manager.import-export.progress')"
			:tips="['模板中红色字段为必填项']"
			:extra-data="{ course_id: course.id }"
	/>
    ```

3. 处理并存储数据，在 `app\Importer` 中定义一个 `Importer` 类，该类继承于 `Modules\Importexport\Importers\CollectionImporter`，重写该类的 `store` 方法，该方法有两个参数 `$row` 和 `$extra_data`

   | 参数 | 类型 | 说明|
       | --- | --- |------------------------|
   | $row | array | Excel 文件上传的一行数据|
   | $extra_data | array | 附属数据 |

    ```php
    public function store(array $row, array $extra): void
    {
        // 通过 $row 和 $extra_data 处理数据并存储，可以进行其它的验证或者是操作，如发送通知提醒等。
        Student::updateOrCreate(['course_id' => $extra['course_id'], 'id_card' => $row['id_card']], array_merge($row, $extra));
    }
    ```


### 数据导出

使用 `Laravel Excel` 提供的 `Excel` 类进行数据的导出，具体使用方法参考 [Laravel Excel](https://docs.laravel-excel.com/3.1/exports/)

#### 开发规范
1. 在 `app\Exporters` 中定义一个 `Exporter` 类，参考如下：

    ```php
    class CourseStudentExporter implements FromQuery, WithHeadings, WithMapping
    {
        use Exportable;
    
        public int $course_id;
    
        public function __construct($course_id)
        {
            $this->course_id = $course_id;
        }
    
        public function query()
        {
            return Student::where('course_id', $this->course_id);
        }
    
        public function headings(): array
        {
            return [
                '学员编号',
                '备注',
                '标签',
            ];
        }
    
        public function map($row): array
        {
            return [
                land_csv_to_string($row->student_num),
                $row->remark,
                implode(', ', $row->tags ?: []),
            ];
        }
    }
    ```
2. 在 Controller 中调用 `Excel` 类的 `download` 方法进行导出，参考如下：

    ```php
    public function export(Request $request, Course $course)
    {
        return (new CourseStudentExporter($course_id))->download("{$course->name}学员导出.xlsx");
    }
    ```


## 模块代码




### Controller

```bash
Modules\Importexport\Http\Controllers\ImportexportController       # 提供导入进度查询接口s
```


### UI


#### PC 组件

```bash
web/components/NewbieImporter.vue        # 导入组件
```

### Service

+ **`ImportexportService`**

    - `readHeaders` 将上传的文件进行存储并读取 Excel 文件的表头

    ```php
      /**
      * 保存文件并读取文件头
      * @param UploadedFile $file
      * @return array
        */
        public function readHeaders(UploadedFile $file): array
    ```

    - `import` 导入数据

    ```php
      /**
      * 导入数据，如果上传数据中有文件，将文件保存到本地，并返回文件路径与表头，如果不含文件而只有文件路径，则进行数据导入
      * @param string $title 标题
      * @param string $importer 导入器
      * @param array $fields 字段
      * @param array $extra_data 额外数据
      * @return array
        */
        public function import(string $title, string $importer, array $fields, array $extra_data = []): array
    ```
