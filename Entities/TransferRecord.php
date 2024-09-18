<?php

namespace Modules\Importexport\Entities;


use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\Approval\Contracts\ApprovableTarget;
use Modules\Approval\Traits\Approvable;
use Modules\Starter\Entities\BaseModel;

class TransferRecord extends BaseModel implements ApprovableTarget
{
    use Approvable;

    protected $model_name = '文件传输';
    protected $model_slug = 'transfer_record';

    const TYPE_IMPORT = 'import';
    const TYPE_EXPORT = 'export';

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE = 'done';

    protected $casts = [
        'properties' => 'array',
        'approval_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime'
    ];

    protected $accessors = [
        'approval_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime'
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function getApproveTodoMessage(): string
    {
        $this->loadMissing(['creator:id,name,work_num']);
        return "{$this->creator->name}（工号：{$this->creator->work_num}）导出任务：{$this->task_name}";
    }

    public function getInitiator(): Authenticatable
    {
        return $this->creator;
    }
}
