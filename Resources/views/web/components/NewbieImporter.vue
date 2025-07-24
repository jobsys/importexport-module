<template>
	<a-modal v-model:open="state.visible" :title="title" :width="700" :mask-closable="false" :footer="null" @cancel="closeImporter">
		<a-steps :current="state.stepNum - 1" size="small" class="my-6!">
			<a-step title="上传文件" />
			<a-step title="设置匹配规则" />
			<a-step title="正在导入" />
			<a-step title="导入完成" />
		</a-steps>

		<div v-if="state.stepNum === 1">
			<ol class="pl-6 py-4 border-solid border-left border-red-200 rounded-lg">
				<li>
					仅支持 EXCEL 文件。
					<a
						class="text-white! transition-all ease-in-out duration-300 font-bold inline-block py-1 px-4 shadow-lg: rounded bg-orange-500! border-2 border-orange-500! hover:bg-white! hover:text-orange-500!"
						v-if="templateUrl"
						:href="uncachedTemplateUrl"
						target="_blank"
					>
						<DownloadOutlined class="mr-1" />
						点击下载导入模板
					</a>
				</li>

				<li>请尽量确保上传数据的列名与目标数据的列名一致，系统将根据列名自动匹配。</li>
				<li>重复数据不会多次导入</li>

				<li v-for="(tip, index) in tips" :key="index">{{ tip }}</li>
			</ol>
			<div class="mt-5">
				<a-upload-dragger
					v-model:fileList="state.fileList"
					name="file"
					:data="extraData"
					:action="state.prepareUrl"
					accept=".xls,.xlsx,.csv"
					@change="uploadSuccessHandler"
				>
					<div class="p-5">
						<CloudUploadOutlined style="color: #3399ff; font-size: 60px"></CloudUploadOutlined>
						<p>点击此处或拖入文件进行上传</p>
					</div>
				</a-upload-dragger>
			</div>
		</div>

		<div v-if="state.stepNum === 2" class="mt-5">
			<a-table
				:columns="state.tableColumns"
				:pagination="false"
				:scroll="{ y: 400 }"
				:data-source="state.mappingTable"
				:loading="state.loading"
			>
				<template #bodyCell="{ column, record, index }">
					<a-select
						v-if="column.dataIndex === 'value'"
						v-model:value="record.value"
						class="w-[150px]"
						:options="state.tableOptions"
						allow-clear
						size="small"
						@change="(value) => selectField(index, value)"
					></a-select>
				</template>
			</a-table>
			<div class="text-center">
				<a-button type="primary" class="my-3" :loading="state.isLoadingNext.loading" @click="nextStep"> 下一步 </a-button>
			</div>
		</div>

		<div v-if="state.stepNum >= 3" class="text-center my-12">
			<a-progress :percent="state.progressPercent || 0" type="circle" class="text-center" :status="state.progressStatus">
				<template #format> {{ state.progressText }}</template>
			</a-progress>

			<div v-if="state.stepNum === 4" class="mt-4">
				<a-alert
					v-if="state.isFailed"
					message="服务异常"
					:description="`已导入【 ${state.processedRows}】 条数据，导入中断，请联系管理员。`"
					type="error"
					show-icon
				/>
				<div v-else-if="state.errorRows">
					<div class="text-center text-gray-500 p-4 rounded">提示：请手动刷新页面或表格查看导入数据</div>
					<div class="text-left bg-gray-100 p-4 rounded">
						<p class="mb-0">
							<a-tag color="red"> {{ state.errorRows }}</a-tag>
							条异常数据，其余数据已正常导入。
							<a-button class="p-0!" type="link" @click="onDownloadErrorFile" :icon="h(DownloadOutlined)"> 下载异常数据文件 </a-button>
						</p>
					</div>
				</div>
				<div v-else>
					<a-alert
						message="导入完成"
						:description="`已导入 ${state.processedRows} 条数据，请手动刷新页面或表格查看导入数据。`"
						type="success"
					/>
				</div>
			</div>
		</div>
	</a-modal>
</template>
<script setup>
import { computed, h, inject, reactive } from "vue"
import { CloudUploadOutlined, DownloadOutlined } from "@ant-design/icons-vue"
import { message, Tag } from "ant-design-vue"
import { STATUS, useFetch, useHiddenForm, useProcessStatusSuccess } from "jobsys-newbie/hooks"
import { isObject } from "lodash-es"

const { STATE_CODE_SUCCESS } = STATUS

const props = defineProps({
	url: { type: [String, Object], default: "" },
	templateUrl: { type: String, default: "" }, //模板链接
	progressUrl: { type: String, default: "" },
	errorUrl: { type: String, default: "" },
	title: { type: String, default: "数据导入" },
	tips: { type: Array, default: () => [] },
	extraData: { type: Object, default: () => ({}) },
})

const route = inject("route")

const state = reactive({
	visible: false,
	prepareUrl: isObject(props.url) ? props.url.prepareUrl : props.url,
	importUrl: isObject(props.url) ? props.url.importUrl : props.url,
	progressFetcher: { loading: false },
	stepNum: 0,
	checkTimes: 0,
	fileList: [],
	mappingTable: [],
	loading: false,
	isLoadingNext: { loading: false },
	uploadFileName: "",
	taskId: "",
	errorRows: "",
	processedRows: "",
	totalRows: "",
	progressPercent: 0,
	progressText: "",
	progressStatus: "normal",
	isFailed: false,
	checkInterval: "",
	tableOptions: [],
	tableColumns: [
		{
			title: "系统数据项",
			dataIndex: "field",
			width: 150,
		},
		{
			title: "是否必填",
			width: 100,
			key: "required",
			customRender: ({ record }) =>
				record.required ? h(Tag, { color: "red" }, { default: () => "是" }) : h(Tag, { type: "default" }, { default: () => "否" }),
		},
		{
			title: "上传数据项",
			dataIndex: "value",
			width: 150,
		},
	],
})

// 防止模板缓存
const uncachedTemplateUrl = computed(() => {
	if (!props.templateUrl) {
		return ""
	}
	return `${props.templateUrl}?t=${new Date().getTime()}`
})

const openImporter = () => {
	state.visible = true
	state.progressFetcher.loading = false
	state.stepNum = 1
	state.loading = false
	state.isLoadingNext.loading = false
	state.progressPercent = 0
	state.errorRows = 0
	state.progressText = ""
	state.progressStatus = "normal"
	state.uploadFileName = ""
	state.isFailed = false
	state.taskId = ""
	state.tableOptions = []
	state.mappingTable = []
	state.fileList = []
}

/**
 * alias for openImporter
 */
const open = () => openImporter()

const onDownloadErrorFile = () => {
	useHiddenForm({
		url: route("api.manager.import-export.download-error-file"),
		data: { task_id: state.taskId },
	}).submit()
}

const checkUploadProgressInterval = () => {
	if (!state.checkInterval) {
		state.checkInterval = setInterval(checkUploadProgress, 5000)
	}
}

const nextStep = async () => {
	if (state.stepNum === 1) {
		if (!state.uploadFileName) {
			message.error("请先上传文件")
			return
		}
		state.stepNum = 2
		return
	}
	if (state.stepNum === 2) {
		let valid = true
		state.mappingTable.forEach((item) => {
			if (item.required && !item.value) {
				valid = false
				message.warning(`"${item.field}" 为必选项`)
			}
		})
		if (!valid) return

		const tempConcatMapping = state.mappingTable.concat()

		for (let i = 0; i < tempConcatMapping.length; i += 1) {
			if (tempConcatMapping[i].required && !tempConcatMapping[i].value) {
				message.error("请先选择所有必选项")
				return
			}
		}

		const params = {
			path: state.uploadFileName,
			headers: state.mappingTable.map((item) => item.value),
		}
		const res = await useFetch(state.isLoadingNext).post(state.importUrl, { ...params, ...props.extraData })
		useProcessStatusSuccess(res, () => {
			state.taskId = res.result.task_id
			state.stepNum = 3
			state.progressText = "预处理..."
			checkUploadProgressInterval()
		})
	}
}

const uploadSuccessHandler = ({ file, fileList }) => {
	if (file.status === "done" && file.response) {
		if (file.response.status !== STATE_CODE_SUCCESS) {
			message.error(file.response.result || "上传失败")
			return
		}
		message.success("文件上传成功, 请设置表头匹配关系")
		const { result } = file.response
		state.uploadFileName = result.path
		nextStep()
		state.mappingTable = result.fields.map((field) => ({
			field: field[0],
			required: field[1],
			value: result.headers.indexOf(field[0]) === -1 ? "" : field[0],
		}))
		state.tableOptions = result.headers.map((item) => ({
			value: item,
			label: item,
		}))
		fileList = []
	}
	state.fileList = fileList
}

const selectField = (index, value) => {
	state.mappingTable = state.mappingTable.map((item, i) => {
		if (index !== i && value === item.value) {
			item.value = ""
		} else if (index === i) {
			item.value = value
		}
		return item
	})
}

const clearCheckInterval = () => {
	clearInterval(state.checkInterval)
	state.checkInterval = ""
}

const checkUploadProgress = async () => {
	if (state.progressFetcher.loading) {
		return
	}

	const progressUrl = props.progressUrl || route("api.manager.import-export.import.progress")

	const res = await useFetch(state.progressFetcher).post(progressUrl, { ids: [state.taskId] })

	const progress = res.result[0]

	if (progress) {
		state.checkTimes = 0
		let isNeedCheckInterval = true
		if (state.stepNum === 3) {
			if (progress.error) {
				state.errorRows = progress.error
			}
			state.progressStatus = "active"
			state.processedRows = progress.processed
			state.totalRows = progress.total
			state.progressPercent = progress.percentage
			state.progressText = `${progress.percentage}%`
			if (progress.status === "done") {
				state.progressStatus = "success"
				state.progressPercent = progress.percentage
				state.stepNum = 4
				clearCheckInterval()
				isNeedCheckInterval = false
			} else if (progress.status === "failed") {
				state.stepNum = 4
				clearCheckInterval()
				isNeedCheckInterval = false
				state.isFailed = true
				state.progressText = "导入中断"
				state.progressStatus = "exception"
			}
		}
		if (isNeedCheckInterval && !state.checkInterval) {
			checkUploadProgressInterval()
		}
	} else {
		if (state.checkTimes > 10) {
			clearCheckInterval()
		}
		state.checkTimes += 1
	}
}

const closeImporter = () => {
	state.visible = false
	clearCheckInterval()
}

defineExpose({ openImporter, open })
</script>
