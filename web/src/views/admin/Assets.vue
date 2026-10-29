<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, type AssetRow, type Paged } from '@/api'
import Icon from '@/components/Icon.vue'
import AssetImport from '@/components/AssetImport.vue'
import { meta, toast } from '@/store'

const route = useRoute()
const router = useRouter()

const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const filters = reactive({ q: q('q'), category_id: q('category_id'), status: q('status'), company: q('company') })
const page = ref(Number(q('page')) || 1)
const result = ref<Paged<AssetRow> | null>(null)
const loading = ref(false)
const selected = ref<Set<number>>(new Set())
const showImport = ref(false)

function imported() {
  selected.value = new Set()
  if (page.value !== 1) page.value = 1
  else load()
}

async function load() {
  loading.value = true
  try {
    result.value = await api.get<Paged<AssetRow>>('/admin/assets', { ...filters, page: page.value, per_page: 50 })
  } catch (e) {
    toast((e as Error).message, 'error')
  } finally {
    loading.value = false
  }
}

watch(
  [filters, page],
  () => {
    router.replace({ query: Object.fromEntries(Object.entries({ ...filters, page: page.value > 1 ? page.value : '' }).filter(([, v]) => v !== '')) })
    load()
  },
  { deep: true, immediate: true },
)

let searchTimer: ReturnType<typeof setTimeout>
function onSearch(e: Event) {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.q = (e.target as HTMLInputElement).value
    page.value = 1
  }, 300)
}

function toggle(id: number) {
  const s = new Set(selected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selected.value = s
}

const allOnPage = computed(() => !!result.value?.data.length && result.value.data.every((a) => selected.value.has(a.id)))
function toggleAll() {
  const s = new Set(selected.value)
  for (const a of result.value?.data ?? []) allOnPage.value ? s.delete(a.id) : s.add(a.id)
  selected.value = s
}

function printQr() {
  const query = selected.value.size ? { ids: [...selected.value].join(',') } : Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
  window.open(router.resolve({ name: 'qr-print', query }).href, '_blank')
}

const statusTone: Record<string, string> = {
  active: 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300',
  repair: 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  spare: 'bg-slate-100 dark:bg-slate-900 text-slate-600 dark:text-slate-300',
  retired: 'bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400',
}
const totalPages = () => (result.value ? Math.max(1, Math.ceil(result.value.total / result.value.per_page)) : 1)
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">ทะเบียนอุปกรณ์</h1>
    <div class="flex flex-wrap gap-2">
      <button class="btn-secondary" @click="showImport = true"><Icon name="upload" :size="16" /> Import Excel</button>
      <RouterLink :to="{name:'document-new',query:{form:'FM-IT-001',company:filters.company||'ATS'}}" class="btn-secondary">เอกสาร FM-IT-001</RouterLink>
      <button class="btn-secondary" @click="printQr"><Icon name="qr" :size="16" /> พิมพ์ QR {{ selected.size ? `(${selected.size})` : 'ตามตัวกรอง' }}</button>
      <RouterLink :to="{ name: 'asset-new' }" class="btn-primary"><Icon name="plus" :size="16" /> เพิ่มอุปกรณ์</RouterLink>
    </div>
  </div>

  <div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
    <div class="relative lg:col-span-2">
      <Icon name="search" :size="16" class="absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
      <input :value="filters.q" class="input pl-9" placeholder="รหัส / รุ่น / Serial / ชื่อเครื่อง / ผู้ใช้ / หน่วยงาน" @input="onSearch" />
    </div>
    <select v-model="filters.category_id" class="input" @change="page = 1">
      <option value="">ทุกประเภท</option>
      <option v-for="c in meta!.categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
    </select>
    <select v-model="filters.status" class="input" @change="page = 1">
      <option value="">ทุกสถานะ</option>
      <option v-for="(label, k) in meta!.asset_statuses" :key="k" :value="k">{{ label }}</option>
    </select>
    <select v-model="filters.company" class="input" @change="page = 1">
      <option value="">ทุกบริษัท</option>
      <option value="ATS">ATS</option>
      <option value="INNO">INNO</option>
    </select>
  </div>

  <p v-if="selected.size" class="mt-3 flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300">
    เลือกไว้ {{ selected.size }} เครื่อง
    <button class="font-medium text-brand-700 dark:text-brand-300" @click="selected = new Set()">ล้างที่เลือก</button>
  </p>

  <section class="card mt-4 overflow-hidden">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th class="w-10"><input type="checkbox" class="size-4 accent-brand-700" :checked="allOnPage" aria-label="เลือกทั้งหน้า" @change="toggleAll" /></th>
            <th>รหัส</th>
            <th>ยี่ห้อ / รุ่น</th>
            <th>ผู้ใช้งาน</th>
            <th>หน่วยงาน</th>
            <th>สถานะ</th>
          </tr>
        </thead>
        <tbody :class="loading && 'opacity-50'">
          <tr v-for="a in result?.data" :key="a.id" class="cursor-pointer" @click="router.push({ name: 'asset-detail', params: { id: a.id } })">
            <td @click.stop><input type="checkbox" class="size-4 accent-brand-700" :checked="selected.has(a.id)" :aria-label="`เลือก ${a.asset_code}`" @change="toggle(a.id)" /></td>
            <td class="whitespace-nowrap">
              <RouterLink :to="{ name: 'asset-detail', params: { id: a.id } }" class="font-mono font-semibold text-brand-700 dark:text-brand-300" @click.stop>{{ a.asset_code }}</RouterLink>
              <p class="text-xs text-slate-500 dark:text-slate-400">{{ a.category_name }} · {{ a.company }}</p>
            </td>
            <td class="min-w-48">
              {{ a.brand_model }}
              <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ a.serial_no }}<span v-if="a.hostname"> · {{ a.hostname }}</span></p>
            </td>
            <td class="whitespace-nowrap">
              {{ a.holder_name ?? '-' }}
              <p v-if="a.holder_nickname" class="text-xs text-slate-500 dark:text-slate-400">{{ a.holder_nickname }}</p>
            </td>
            <td class="min-w-36 text-slate-600 dark:text-slate-300">{{ a.department }}</td>
            <td class="whitespace-nowrap">
              <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusTone[a.status]]">{{ meta?.asset_statuses[a.status] }}</span>
              <p v-if="a.open_tickets" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">งานค้าง {{ a.open_tickets }}</p>
            </td>
          </tr>
          <tr v-if="result && !result.data.length">
            <td colspan="6" class="py-12 text-center text-slate-400">ไม่พบอุปกรณ์</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="result" class="flex items-center justify-between border-t border-slate-100 dark:border-slate-700 px-4 py-3 text-sm">
      <span class="text-slate-500 dark:text-slate-400">ทั้งหมด {{ result.total }} เครื่อง</span>
      <div v-if="result.total > result.per_page" class="flex items-center gap-2">
        <button class="btn-secondary px-3 py-1.5" :disabled="page <= 1" @click="page--">ก่อนหน้า</button>
        <span>{{ page }} / {{ totalPages() }}</span>
        <button class="btn-secondary px-3 py-1.5" :disabled="page >= totalPages()" @click="page++">ถัดไป</button>
      </div>
    </div>
  </section>
  <AssetImport v-if="showImport" @close="showImport = false" @imported="imported" />
</template>
