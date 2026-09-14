<script setup>
import { ref, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import {
  X, History, Eye, Undo2, ArrowLeft, Loader2,
  FileText, CheckCircle, AlertCircle, ChevronLeft, ChevronRight
} from 'lucide-vue-next';
import axios from 'axios';

const props = defineProps({
  isOpen: Boolean
});

const emit = defineEmits(['close', 'batch-reverted']);

const API = 'http://localhost:8000/api';

// 视图状态：list（批次列表）| detail（批次影片明细）
const view = ref('list');
const loading = ref(false);
const error = ref(null);

// 批次列表
const batches = ref([]);
const currentPage = ref(1);
const lastPage = ref(1);

// 批次详情
const selectedBatch = ref(null);
const items = ref([]);
const itemsPage = ref(1);
const itemsLastPage = ref(1);
const itemsLoading = ref(false);

// 撤销
const confirmRevertId = ref(null); // 待二次确认的批次 id
const reverting = ref(false);
const revertResult = ref(null); // { message, type }

const fetchBatches = async (page = 1) => {
  loading.value = true;
  error.value = null;
  try {
    const response = await axios.get(`${API}/import-batches`, { params: { page } });
    batches.value = response.data.data;
    currentPage.value = response.data.current_page;
    lastPage.value = response.data.last_page;
  } catch (err) {
    error.value = '加载导入记录失败，请稍后重试。';
  } finally {
    loading.value = false;
  }
};

const openDetail = async (batch, page = 1) => {
  view.value = 'detail';
  itemsLoading.value = true;
  error.value = null;
  try {
    const response = await axios.get(`${API}/import-batches/${batch.id}`, { params: { page } });
    selectedBatch.value = response.data.batch;
    items.value = response.data.items.data;
    itemsPage.value = response.data.items.current_page;
    itemsLastPage.value = response.data.items.last_page;
  } catch (err) {
    error.value = '加载批次详情失败，请稍后重试。';
  } finally {
    itemsLoading.value = false;
  }
};

const backToList = () => {
  view.value = 'list';
  selectedBatch.value = null;
  items.value = [];
};

const revertBatch = async (batch) => {
  reverting.value = true;
  revertResult.value = null;
  try {
    const response = await axios.post(`${API}/import-batches/${batch.id}/revert`);
    const { deleted, kept } = response.data;
    revertResult.value = {
      type: 'success',
      message: `批次 #${batch.id} 已撤销：删除 ${deleted} 部新增影片` +
        (kept > 0 ? `，保留 ${kept} 部（被其他批次引用）` : '。')
    };
    confirmRevertId.value = null;
    emit('batch-reverted'); // 通知父组件刷新影片列表
    await fetchBatches(currentPage.value);
    if (view.value === 'detail' && selectedBatch.value?.id === batch.id) {
      await openDetail(batch, itemsPage.value);
    }
  } catch (err) {
    revertResult.value = {
      type: 'error',
      message: err.response?.data?.error || '撤销失败，请稍后重试。'
    };
  } finally {
    reverting.value = false;
  }
};

const formatTime = (value) => {
  if (!value) return '-';
  return new Date(value).toLocaleString('zh-CN', { hour12: false });
};

// 每次打开弹窗时回到列表并刷新
watch(() => props.isOpen, (open) => {
  if (open) {
    view.value = 'list';
    revertResult.value = null;
    confirmRevertId.value = null;
    fetchBatches(1);
  }
});
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="$emit('close')" class="relative z-50">
      <TransitionChild
        as="template"
        enter="duration-300 ease-out"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="duration-200 ease-in"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/80 backdrop-blur-sm" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <TransitionChild
            as="template"
            enter="duration-300 ease-out"
            enter-from="opacity-0 scale-95"
            enter-to="opacity-100 scale-100"
            leave="duration-200 ease-in"
            leave-from="opacity-100 scale-100"
            leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-3xl transform overflow-hidden rounded-2xl bg-dark-800 p-6 text-left align-middle shadow-xl transition-all border border-white/10">
              <!-- Header -->
              <div class="flex items-center justify-between mb-4">
                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-white flex items-center gap-2">
                  <button v-if="view === 'detail'" @click="backToList" class="text-gray-400 hover:text-white mr-1">
                    <ArrowLeft class="h-5 w-5" />
                  </button>
                  <History class="h-5 w-5 text-purple-400" />
                  {{ view === 'list' ? '导入记录' : `批次 #${selectedBatch?.id} 详情` }}
                </DialogTitle>
                <button @click="$emit('close')" class="text-gray-400 hover:text-white">
                  <X class="h-5 w-5" />
                </button>
              </div>

              <!-- 撤销结果 / 错误提示 -->
              <div v-if="revertResult" class="mb-4 flex items-start gap-2 rounded-lg p-3 text-sm"
                :class="revertResult.type === 'success' ? 'bg-green-500/10 text-green-400' : 'bg-red-500/10 text-red-400'">
                <CheckCircle v-if="revertResult.type === 'success'" class="h-4 w-4 mt-0.5 shrink-0" />
                <AlertCircle v-else class="h-4 w-4 mt-0.5 shrink-0" />
                <span>{{ revertResult.message }}</span>
              </div>
              <div v-if="error" class="mb-4 flex items-start gap-2 rounded-lg bg-red-500/10 p-3 text-sm text-red-400">
                <AlertCircle class="h-4 w-4 mt-0.5 shrink-0" />
                <span>{{ error }}</span>
              </div>

              <!-- ============ 批次列表 ============ -->
              <div v-if="view === 'list'">
                <div v-if="loading" class="flex h-48 items-center justify-center">
                  <Loader2 class="h-8 w-8 animate-spin text-purple-500" />
                </div>

                <div v-else-if="batches.length === 0" class="flex h-48 flex-col items-center justify-center text-center">
                  <FileText class="h-10 w-10 text-gray-600 mb-3" />
                  <p class="text-gray-400">暂无导入记录</p>
                </div>

                <div v-else class="space-y-3 max-h-[55vh] overflow-y-auto pr-1">
                  <div v-for="batch in batches" :key="batch.id"
                    class="rounded-xl border border-white/5 bg-white/5 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                      <div class="min-w-0">
                        <div class="flex items-center gap-2">
                          <span class="text-xs font-mono text-gray-500">#{{ batch.id }}</span>
                          <p class="truncate text-sm font-medium text-white">{{ batch.filename }}</p>
                          <span v-if="batch.status === 'reverted'"
                            class="shrink-0 rounded-full bg-gray-500/20 px-2 py-0.5 text-xs text-gray-400">已撤销</span>
                          <span v-else
                            class="shrink-0 rounded-full bg-green-500/20 px-2 py-0.5 text-xs text-green-400">已完成</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                          {{ batch.importer_name || '匿名' }} · {{ formatTime(batch.created_at) }}
                        </p>
                        <p class="mt-1 text-xs">
                          <span class="text-green-400">新增 {{ batch.success_count }}</span>
                          <span class="text-gray-500"> · </span>
                          <span class="text-yellow-400">跳过 {{ batch.skipped_count }}</span>
                          <span class="text-gray-500"> · </span>
                          <span :class="batch.error_count > 0 ? 'text-red-400' : 'text-gray-500'">失败 {{ batch.error_count }}</span>
                        </p>
                      </div>

                      <div class="flex shrink-0 items-center gap-2">
                        <button @click="openDetail(batch)"
                          class="flex items-center gap-1 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/20">
                          <Eye class="h-3.5 w-3.5" /> 查看影片
                        </button>

                        <!-- 撤销：两步确认 -->
                        <template v-if="batch.status !== 'reverted'">
                          <button v-if="confirmRevertId !== batch.id"
                            @click="confirmRevertId = batch.id"
                            class="flex items-center gap-1 rounded-lg bg-red-500/10 px-3 py-1.5 text-xs font-medium text-red-400 hover:bg-red-500/20">
                            <Undo2 class="h-3.5 w-3.5" /> 撤销
                          </button>
                          <template v-else>
                            <button @click="revertBatch(batch)" :disabled="reverting"
                              class="flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-500 disabled:opacity-50">
                              <Loader2 v-if="reverting" class="h-3.5 w-3.5 animate-spin" />
                              确认撤销？
                            </button>
                            <button @click="confirmRevertId = null"
                              class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-gray-300 hover:bg-white/20">
                              取消
                            </button>
                          </template>
                        </template>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- 列表分页 -->
                <div v-if="lastPage > 1" class="mt-4 flex items-center justify-center gap-4">
                  <button @click="fetchBatches(currentPage - 1)" :disabled="currentPage === 1"
                    class="rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-white disabled:opacity-30">
                    <ChevronLeft class="h-5 w-5" />
                  </button>
                  <span class="text-sm text-gray-400">{{ currentPage }} / {{ lastPage }}</span>
                  <button @click="fetchBatches(currentPage + 1)" :disabled="currentPage === lastPage"
                    class="rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-white disabled:opacity-30">
                    <ChevronRight class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- ============ 批次详情 ============ -->
              <div v-else>
                <div v-if="selectedBatch" class="mb-4 rounded-xl border border-white/5 bg-white/5 p-4 text-sm">
                  <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span class="text-gray-400">文件：<span class="text-white">{{ selectedBatch.filename }}</span></span>
                    <span class="text-gray-400">导入人：<span class="text-white">{{ selectedBatch.importer_name || '匿名' }}</span></span>
                    <span class="text-gray-400">时间：<span class="text-white">{{ formatTime(selectedBatch.created_at) }}</span></span>
                    <span v-if="selectedBatch.status === 'reverted'" class="rounded-full bg-gray-500/20 px-2 py-0.5 text-xs text-gray-400">
                      已撤销 · {{ formatTime(selectedBatch.reverted_at) }}
                    </span>
                  </div>
                  <p class="mt-2 text-xs">
                    <span class="text-green-400">新增 {{ selectedBatch.success_count }}</span>
                    <span class="text-gray-500"> · </span>
                    <span class="text-yellow-400">跳过 {{ selectedBatch.skipped_count }}</span>
                    <span class="text-gray-500"> · </span>
                    <span :class="selectedBatch.error_count > 0 ? 'text-red-400' : 'text-gray-500'">失败 {{ selectedBatch.error_count }}</span>
                  </p>
                  <!-- 错误摘要 -->
                  <div v-if="selectedBatch.errors && selectedBatch.errors.length > 0"
                    class="mt-3 max-h-28 overflow-y-auto rounded bg-black/30 p-2 text-xs font-mono text-red-300">
                    <div v-for="(err, i) in selectedBatch.errors" :key="i">{{ err }}</div>
                  </div>
                  <!-- 详情页撤销入口（两步确认） -->
                  <div v-if="selectedBatch.status !== 'reverted'" class="mt-3 flex items-center gap-2">
                    <button v-if="confirmRevertId !== selectedBatch.id"
                      @click="confirmRevertId = selectedBatch.id"
                      class="flex items-center gap-1 rounded-lg bg-red-500/10 px-3 py-1.5 text-xs font-medium text-red-400 hover:bg-red-500/20">
                      <Undo2 class="h-3.5 w-3.5" /> 撤销本批次
                    </button>
                    <template v-else>
                      <button @click="revertBatch(selectedBatch)" :disabled="reverting"
                        class="flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-500 disabled:opacity-50">
                        <Loader2 v-if="reverting" class="h-3.5 w-3.5 animate-spin" />
                        确认撤销？将删除 {{ selectedBatch.success_count }} 部新增影片
                      </button>
                      <button @click="confirmRevertId = null"
                        class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-gray-300 hover:bg-white/20">
                        取消
                      </button>
                    </template>
                  </div>
                </div>

                <div v-if="itemsLoading" class="flex h-48 items-center justify-center">
                  <Loader2 class="h-8 w-8 animate-spin text-purple-500" />
                </div>

                <div v-else>
                  <table class="w-full text-left text-sm">
                    <thead>
                      <tr class="border-b border-white/10 text-xs text-gray-500">
                        <th class="pb-2 pr-4 font-medium">影片</th>
                        <th class="pb-2 pr-4 font-medium w-20">年份</th>
                        <th class="pb-2 pr-4 font-medium w-24">导入动作</th>
                        <th class="pb-2 font-medium w-20">当前状态</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="item in items" :key="item.id" class="border-b border-white/5">
                        <td class="py-2.5 pr-4 text-white truncate max-w-[16rem]">{{ item.title }}</td>
                        <td class="py-2.5 pr-4 text-gray-400">{{ item.year }}</td>
                        <td class="py-2.5 pr-4">
                          <span v-if="item.action === 'created'"
                            class="rounded-full bg-green-500/20 px-2 py-0.5 text-xs text-green-400">新增</span>
                          <span v-else
                            class="rounded-full bg-yellow-500/20 px-2 py-0.5 text-xs text-yellow-400">已存在</span>
                        </td>
                        <td class="py-2.5">
                          <span v-if="item.movie_exists" class="text-xs text-gray-400">在库</span>
                          <span v-else class="text-xs text-red-400/70">已删除</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>

                  <div v-if="items.length === 0" class="py-10 text-center text-sm text-gray-500">
                    该批次没有导入任何影片
                  </div>

                  <!-- 明细分页 -->
                  <div v-if="itemsLastPage > 1" class="mt-4 flex items-center justify-center gap-4">
                    <button @click="openDetail(selectedBatch, itemsPage - 1)" :disabled="itemsPage === 1"
                      class="rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-white disabled:opacity-30">
                      <ChevronLeft class="h-5 w-5" />
                    </button>
                    <span class="text-sm text-gray-400">{{ itemsPage }} / {{ itemsLastPage }}</span>
                    <button @click="openDetail(selectedBatch, itemsPage + 1)" :disabled="itemsPage === itemsLastPage"
                      class="rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-white disabled:opacity-30">
                      <ChevronRight class="h-5 w-5" />
                    </button>
                  </div>
                </div>
              </div>

            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
