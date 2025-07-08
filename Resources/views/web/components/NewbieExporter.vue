<template>
	<a-modal v-model:open="state.visible" :title="title" :width="width" :mask-closable="false" :footer="null" @cancel="closeExporter">
		<a-steps :current="state.stepNum - 1" size="small" class="my-6!">
			<a-step title="选择数据" />
			<a-step title="定制字段" />
			<a-step title="导出审核" />
		</a-steps>

		<div v-if="state.stepNum === 1">
			<ol class="pl-6 py-4 border-solid border-left border-red-200 rounded-lg">
				<li>导出勾选数据 - 请先在页面表格中勾选数据后再进行导出</li>
				<li v-for="(tip, index) in tips" :key="index">{{ tip }}</li>
			</ol>
			<a-divider>请选择导出数据范围</a-divider>
			<div class="flex justify-center">
				<a-radio-group v-model:value="state.selectedMode" button-style="solid" class="mt-5! mb-20!">
					<a-tooltip
						v-if="availableModes.includes('query')"
						:open="state.selectedMode === 'query'"
						placement="bottomLeft"
						title="导出按当前查询条件查找的所有数据"
					>
						<a-radio-button value="query" :disabled="isTableEmpty">
							<SearchOutlined></SearchOutlined>
							导出查询数据
						</a-radio-button>
					</a-tooltip>
					<a-tooltip :open="state.selectedMode === 'selection'" placement="bottom" title="导出表格中勾选的数据">
						<a-radio-button value="selection" :disabled="!selectionRows?.length">
							<CheckSquareOutlined class="mr-2"></CheckSquareOutlined>
							导出勾选数据
						</a-radio-button>
					</a-tooltip>
					<a-tooltip :open="state.selectedMode === 'page'" placement="bottom" title="仅导出当前页面数据">
						<a-radio-button value="page" :disabled="isTableEmpty">
							<FileTextOutlined class="mr-2"></FileTextOutlined>
							导出本页数据
						</a-radio-button>
					</a-tooltip>
					<a-tooltip :open="state.selectedMode === 'all'" placement="bottomRight">
						<template #title>
							<div>导出该业务所有数据</div>
						</template>
						<a-radio-button value="all">
							<DatabaseOutlined class="mr-2"></DatabaseOutlined>
							导出全部数据
						</a-radio-button>
					</a-tooltip>
				</a-radio-group>
			</div>
			<div class="flex justify-center">
				<a-button type="primary" class="my-3!" :loading="state.isLoadingNext.loading" @click="nextStep"> 下一步 </a-button>
			</div>
		</div>

		<div v-if="state.stepNum === 2" class="mt-5">
			<div class="bg-gray-50 p-4! rounded">
				<a-checkbox class="mt-4!" v-model:checked="state.checkAll" :indeterminate="state.indeterminate" @change="onCheckAllChange">
					<span class="font-bold">全选</span>
				</a-checkbox>
				<a-divider></a-divider>

				<a-checkbox-group v-model:value="state.checkedFields" class="w-full">
					<a-row :gutter="15">
						<a-col :span="6" v-for="(field, index) in state.fields" :key="index" class="mb-3! exporter-checkbox-col">
							<a-checkbox :value="field">{{ field }}</a-checkbox>
						</a-col>
					</a-row>
				</a-checkbox-group>
			</div>
			<div class="text-center mt-6! mb-3!">
				<a-button @click="() => (state.stepNum -= 1)">上一步</a-button>
				<a-button type="primary" class="ml-2" :loading="state.isLoadingNext.loading" @click="nextStep"> 提交审核 </a-button>
			</div>
		</div>

		<div v-if="state.stepNum === 3">
			<div class="text-center mt-6! mb-3!">
				<div v-if="state.approvalStatus === 'pending'">
					<a-alert message="导出任务审核中" type="info" show-icon>
						<template #description>
							导出文件正在审核，请耐心等待，审核通过后可以在
							<Link :href="route('page.manager.tool.data-transfer')">系统工具 - 数据传输</Link>
							中进行下载
						</template>
					</a-alert>
				</div>
				<div v-if="state.approvalStatus === 'approved'">
					<a-alert message="导出任务审核通过" type="success" show-icon>
						<template #description> 点击下方下载按钮下载文件</template>
					</a-alert>
					<div class="text-center mt-6! mb-3!">
						<a-button @click="() => (state.visible = false)">关闭</a-button>
						<a-button type="primary" class="ml-2 mt-5" :loading="state.isLoadingNext.loading" @click="nextStep"> 下载文件 </a-button>

						<p class="mt-4 text-gray-500">
							若数据量较大，或请求超时出错，可在
							<Link :href="route('page.manager.tool.data-transfer')">系统工具 - 数据传输</Link>
							中查看进度，并于任务完成后下载文件
						</p>
					</div>
				</div>
			</div>
		</div>
	</a-modal>
</template>
<script setup>
import { computed, inject, reactive, watch } from "vue"
import { message } from "ant-design-vue"
import { CheckSquareOutlined, DatabaseOutlined, FileTextOutlined, SearchOutlined } from "@ant-design/icons-vue"
import { useFetch, useHiddenForm, useProcessStatusSuccess } from "jobsys-newbie/hooks"
import { clone, isString } from "lodash-es"
import { Link } from "@inertiajs/vue3"

const props = defineProps({
	width: {
		type: Number,
		default: 700,
	},
	url: {
		// 上传URL
		type: [String, Object],
		default: "",
	},
	title: {
		// 标题
		type: String,
		default: "",
	},
	tips: {
		// 提示
		type: Array,
		default: () => [],
	},
	extraData: {
		// 附加参数，只有 mode 为 query 时生效
		type: Object,
		default: () => ({}),
	},
	tableRef: {
		// 表格实例
		type: Object,
		default: () => null,
	},
	modes: {
		// 表格实例
		type: [Array, String],
		default: () => ["query", "selection", "page", "all"],
	},
	defaultFields: {
		// 导出默认字段
		type: Array,
		default: () => [],
	},
})

const route = inject("route")

const state = reactive({
	visible: false,
	stepNum: 0,
	selectedMode: "query",
	isLoadingNext: {
		loading: false,
	},
	fields: [],
	checkedFields: [],
	selectedFields: [],
	indeterminate: false,
	checkAll: false,
	taskId: "",
	approvalStatus: "approved",
	downloadUrl: "",
})

const availableModes = computed(() => {
	const modes = isString(props.modes) ? [props.modes] : props.modes
	return modes.filter((mode) => ["query", "selection", "page", "all"].includes(mode))
})

const selectionRows = computed(() => (props.tableRef ? props.tableRef.getSelection() : []))

const isTableEmpty = computed(() => (props.tableRef ? !props.tableRef.getPagination()?.totalSize : true))

watch(
	() => state.checkedFields,
	(val) => {
		state.indeterminate = !!val.length && val.length < state.fields.length
		state.checkAll = val.length === state.fields.length
	},
)

const onCheckAllChange = (e) => {
	state.checkedFields = e.target.checked ? clone(state.fields) : []
	state.indeterminate = false
}

const openExporter = () => {
	state.visible = true
	state.selectedMode = isTableEmpty.value ? "all" : "query"
	state.stepNum = 1
}

/**
 * alias for openExporter
 */
const open = () => openExporter()

const nextStep = async () => {
	if (state.stepNum === 1) {
		const res = await useFetch(state.isLoadingNext).post(props.url)

		useProcessStatusSuccess(res, () => {
			state.fields = res.result
			state.checkedFields = props.defaultFields?.length ? props.defaultFields : res.result
			state.stepNum = 2
		})
		return
	}

	if (state.stepNum === 2) {
		if (!state.checkedFields?.length) {
			message.warning("请先选择字段")
		}

		let params

		if (state.selectedMode === "query") {
			params = props.tableRef?.getQueryData() || {}
			delete params.page
			delete params.page_size
		} else if (state.selectedMode === "selection") {
			const rowKey = props.tableRef?.$props.rowKey
			params = selectionRows.value.map((row) => row[rowKey])
		} else if (state.selectedMode === "page") {
			const rowKey = props.tableRef?.$props.rowKey
			params = props.tableRef?.getData().map((row) => row[rowKey])
		} else if (state.selectedMode === "all") {
			params = null
		} else {
			message.error("请选择导出数据范围")
			return
		}
		const res = await useFetch(state.isLoadingNext).post(props.url, {
			fields: state.checkedFields,
			mode: state.selectedMode,
			params,
		})
		useProcessStatusSuccess(res, () => {
			state.taskId = res.result.export_id
			state.approvalStatus = res.result.approval_status
			state.stepNum = 3
		})
		return
	}

	if (state.stepNum === 3) {
		if (state.approvalStatus !== "approved") {
			message.error("导出任务未通过审核，请耐心等待")
			return
		}

		const res = await useFetch(state.isLoadingNext).post(props.url, { task_id: state.taskId })
		useProcessStatusSuccess(res, () => {
			useHiddenForm({ url: res.result, data: {} }).submit()
		})
	}
}

const closeExporter = () => {
	state.selectedMode = ""
	state.visible = false
}

defineExpose({ openExporter, open })
</script>

<style lang="less" scoped>
.exporter-checkbox-col {
	.ant-checkbox-wrapper {
		white-space: break-spaces;
	}
}
</style>
