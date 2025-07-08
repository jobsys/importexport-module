<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	/**
	 * Run the migrations.
	 * 导出导出数据传输记录表
	 */
	public function up(): void
	{
		Schema::create('transfer_records', function (Blueprint $table) {
			$table->id();
			$table->string('task_id')->nullable()->index()->comment('任务ID');
			$table->string('task_name')->nullable()->index()->comment('任务名称');
			$table->integer('creator_id')->index()->comment('创建者ID');
			$table->string('type', 20)->index()->comment('类型：import-导入，export-导出');
			$table->string('class_name')->nullable()->comment('类名');
			$table->string('status')->nullable()->index()->comment('状态：pending-待处理，processing-处理中，done-结束');
			$table->string('file_path')->nullable()->comment('文件路径');
			$table->json('properties')->nullable()->comment('附加参数');
			$table->text('error')->nullable()->comment('错误信息');
			$table->string('total_count')->nullable()->comment('总数据量');
			$table->string('error_file_path')->nullable()->comment('错误文件路径');
			$table->dateTime('started_at')->nullable()->comment('开始时间');
			$table->dateTime('ended_at')->nullable()->comment('结束时间');
			$table->string('duration')->nullable()->comment('处理时长');
			$table->integer('approver_id')->nullable()->index()->comment('审核者ID');
			$table->string('approval_status')->nullable()->index()->comment('审核状态');
			$table->string('approval_comment')->nullable()->comment('审核备注');
			$table->dateTime('approval_at')->nullable()->comment('审核时间');
			$table->timestamps();
			$table->comment('数据传输记录表');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('transfer_records');
	}
};
