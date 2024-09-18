<template>
	<a-modal v-model:open="state.visible" :title="title" :width="700" :mask-closable="false" :footer="null" @cancel="closeImporter">
		<a-steps :current="state.stepNum - 1" size="small" class="my-6">
			<a-step title="上传文件" />
			<a-step title="设置匹配规则" />
			<a-step title="正在导入" />
			<a-step title="导入完成" />
		</a-steps>

		<div v-if="state.stepNum === 1">
			<ol class="pl-6 py-4 border-solid border-left border-red-200 rounded-lg">
				<li>
					仅支持 EXCEL 文件。
					<a v-if="templateUrl" :href="templateUrl" target="_blank">&gt;&gt;点击下载模板&lt;&lt;</a>
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
						class="w-[100px]"
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

		<div v-if="state.stepNum === 3" class="text-center mt-6">
			<a-progress :percent="state.progressPercent || 0" type="circle" class="text-center">
				<template #format="percent">
					{{ state.progressText || (percent || 0) + "%" }}
				</template>
			</a-progress>
		</div>

		<div v-if="state.stepNum === 4" class="text-center mt-6">
			<a-progress :percent="100" type="circle" class="text-center">
				<template #format="percent">
					{{ state.progressText || (percent || 0) + "%" }}
				</template>
			</a-progress>
			<div class="text-center text-gray-500 p-4 rounded">提示：请手动刷新页面或表格查看导入数据</div>

			<div v-if="!state.errorFile" class="text-left bg-gray-100 p-4 rounded">
				<p class="mb-0">
					<a-tag color="red"> {{ state.errorRows }}</a-tag>
					条异常数据

					<a :href="state.errorFile" class="mx-3 ant-btn ant-btn-danger" target="_blank">查看错误信息</a>，其余数据已正常导入
				</p>
			</div>
		</div>
	</a-modal>
</template>
<script setup>
import { h, reactive } from "vue"
import { CloudUploadOutlined } from "@ant-design/icons-vue"
import { message, Tag } from "ant-design-vue"
import { STATUS, useFetch, useProcessStatusSuccess } from "jobsys-newbie/hooks"
import { isObject } from "lodash-es"

const { STATE_CODE_SUCCESS } = STATUS

const props = defineProps({
	url: {
		type: [String, Object],
		default: "",
	},

	templateUrl: {
		//模板链接
		type: String,
		default: "",
	},
	progressUrl: {
		type: String,
		default: "",
	},
	errorUrl: {
		type: String,
		default: "",
	},
	title: {
		type: String,
		default: "",
	},
	tips: {
		type: Array,
		default: () => [],
	},
	extraData: {
		type: Object,
		default: () => ({}),
	},
})

const state = reactive({
	visible: false,
	prepareUrl: isObject(props.url) ? props.url.prepareUrl : props.url,
	importUrl: isObject(props.url) ? props.url.importUrl : props.url,
	isCheckingProcess: { loading: false },
	stepNum: 0,
	checkTimes: 0,
	fileList: [],
	mappingTable: [],
	loading: false,
	isLoadingNext: { loading: false },
	uploadFileName: "",
	importId: "",
	errorFile: "",
	errorRows: "",
	progressPercent: 0,
	progressText: "",
	checkInterval: "",
	tableOptions: [],
	tableColumns: [
		{
			title: "系统数据项",
			dataIndex: "field",
		},
		{
			title: "是否必填",
			width: 100,
			key: "required",
			customRender: ({ record }) => {
				return record.required ? h(Tag, { color: "red" }, { default: () => "是" }) : h(Tag, { type: "default" }, { default: () => "否" })
			},
		},
		{
			title: "上传数据项",
			dataIndex: "value",
		},
	],
})

const openImporter = () => {
	state.visible = true
	state.isCheckingProcess.loading = false
	state.stepNum = 1
	state.loading = false
	state.isLoadingNext.loading = false
	state.progressPercent = 0
	state.progressText = ""
	state.uploadFileName = ""
	state.importId = ""
	state.tableOptions = []
	state.mappingTable = []
	state.fileList = []
	state.errorFile = ""
}

/**
 * alias for openImporter
 */
const open = () => openImporter()

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
			state.importId = res.result.import_id
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
		message.success("文件上传成功, 请设置匹配关系")
		const { result } = file.response
		state.uploadFileName = result.path
		nextStep()
		state.mappingTable = result.fields.map((field) => {
			return {
				field: field[0],
				required: field[1],
				value: result.headers.indexOf(field[0]) === -1 ? "" : field[0],
			}
		})
		state.tableOptions = result.headers.map((item) => {
			return {
				value: item,
				label: item,
			}
		})
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
	if (state.isCheckingProcess.loading) {
		return
	}
	const res = await useFetch(state.isCheckingProcess).post(props.progressUrl, { ids: [state.importId] })

	const progress = res.result[0]

	if (progress) {
		state.checkTimes = 0
		let isNeedCheckInterval = true
		if (state.stepNum === 3) {
			state.progressText = ""
			if (progress.error) {
				state.errorFile = progress.error
				state.errorRows = progress.error_rows
			}
			if (progress.current_row >= progress.total_rows) {
				state.progressPercent = 100
				state.stepNum = 4
				clearCheckInterval()
				isNeedCheckInterval = false
			} else {
				state.progressPercent = parseFloat(((progress.current_row / progress.total_rows) * 100).toFixed(2))
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
