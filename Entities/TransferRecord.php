<?php

namespace Modules\Importexport\Entities;


use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Approval\Traits\Approvable;
use Modules\Starter\Entities\BaseModel;

class TransferRecord extends BaseModel
{
	use Approvable;

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
}
