<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import { auth } from '@/store'
import { fmtDateTime, todayInput } from '@/format'
import {
  inspectionItemName, inspectionLabels, inspectionMarks, inspectionMonths,
  type InspectionItem, type InspectionResult, type InspectionYear,
} from '@/inspections'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import InspectionPrint from '@/components/InspectionPrint.vue'
import InspectionImport from '@/components/InspectionImport.vue'

const route = useRoute(), router = useRouter()
const data = ref<InspectionYear | null>(null)
const month = ref(1)
const loading = ref(false), saving = ref(false), printing = ref(false)
const error = ref(''), notice = ref('')
const q = ref('')
/** Unsaved marks of the selected month: item id → result (null = clear). */
const draft = reactive<Record<number, InspectionResult | null>>({})
const monthForm = reactive({ inspected_on: '', inspector: '', reviewer: '', notes: '' })
const results: InspectionResult[] = ['normal', 'abnormal', 'fixed']
const markTone: Record<InspectionResult, string> = {
  normal: 'border-green-600 bg-green-600 text-white', abnormal: 'border-red-600 bg-red-600 text-white', fixed: 'border-sky-600 bg-sky-600 text-white',
}
const miniTone: Record<InspectionResult, string> = {
  normal: 'text-green-700 dark:text-green-400', abnormal: 'text-red-600 dark:text-red-400 font-bold', fixed: 'text-sky-700 dark:text-sky-300 font-bold',
}

const savedMonth = computed(() => data.value?.months[month.value] ?? null)
const draftCount = computed(() => Object.keys(draft).length)
const monthDirty = computed(() => {
  const s = savedMonth.value
  return (s?.inspected_on || '') !== monthForm.inspected_on || (s?.inspector || '') !== monthForm.inspector.trim()
    || (s?.reviewer || '') !== monthForm.reviewer.trim() || (s?.notes || '') !== monthForm.notes.trim()
})
const dirty = computed(() => draftCount.value > 0 || monthDirty.value)
const value = (i: InspectionItem): InspectionResult | null => (i.id in draft ? draft[i.id] : i.results[month.value] ?? null) ?? null
const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  const items = data.value?.items ?? []
  if (!term) return items
  return items.filter(i => [i.asset_code, i.item, i.notes, i.asset?.category, i.asset?.model, i.asset?.holder, i.asset?.department].some(v => v?.toLowerCase().includes(term)))
})
const monthStats = computed(() => Array.from({ length: 12 }, (_, k) => {
  const items = data.value?.items ?? []
  const m = k + 1
  const done = items.filter(i => (m === month.value ? value(i) : i.results[m]) != null).length
  const bad = items.filter(i => (m === month.value ? value(i) : i.results[m]) === 'abnormal').length
  return { done, bad, total: items.length }
}))
const current = computed(() => monthStats.value[month.value - 1])
const yearOptions = computed(() => {
  if (!data.value) return []
  const set = new Set([...data.value.years.map(y => y.year), data.value.current_year, data.value.current_year + 1, data.value.year])
  return [...set].sort((a, b) => b - a)
})
const otherYears = computed(() => (data.value?.years ?? []).filter(y => y.year !== data.value?.year))

function resetMonthForm() {
  const s = savedMonth.value
  monthForm.inspected_on = s?.inspected_on || ''; monthForm.inspector = s?.inspector || ''
  monthForm.reviewer = s?.reviewer || ''; monthForm.notes = s?.notes || ''
}
function clearDraft() { for (const k of Object.keys(draft)) delete draft[Number(k)] }
function confirmDiscard() { return !dirty.value || window.confirm('มีผลตรวจที่ยังไม่บันทึก ต้องการทิ้งการแก้ไขหรือไม่?') }
function defaultMonth(d: InspectionYear) {
  if (d.year === d.current_year) return d.current_month
  const used = Object.keys(d.months).map(Number)
  for (const i of d.items) for (const m of Object.keys(i.results)) used.push(Number(m))
  return used.length ? Math.max(...used) : 1
}
function apply(d: InspectionYear, keepMonth = true) {
  data.value = d
  if (!keepMonth) month.value = defaultMonth(d)
  clearDraft(); resetMonthForm()
  router.replace({ query: { ...route.query, year: String(d.year), month: String(month.value), import: undefined } })
}

async function load(year?: number | string, keepMonth = false) {
  loading.value = true; error.value = ''
  try { apply(await api.get<InspectionYear>('/admin/inspections', { year }), keepMonth) }
  catch (e) { error.value = (e as Error).message }
  finally { loading.value = false }
}
function pickYear(event: Event) {
  const select = event.target as HTMLSelectElement
  if (!confirmDiscard()) { select.value = String(data.value?.year ?? ''); return }
  notice.value = ''; load(select.value)
}
function pickMonth(m: number) {
  if (m === month.value || !confirmDiscard()) return
  month.value = m; clearDraft(); resetMonthForm(); notice.value = ''
  router.replace({ query: { ...route.query, month: String(m) } })
}

/** First mark of an empty month fills the inspector (and today's date when it is this month). */
function prefillMonth() {
  if (!monthForm.inspector && auth.admin) monthForm.inspector = auth.admin.display_name
  if (!monthForm.inspected_on && data.value && data.value.year === data.value.current_year && month.value === data.value.current_month) monthForm.inspected_on = todayInput()
}
function setMark(i: InspectionItem, r: InspectionResult) {
  const next = value(i) === r ? null : r
  if ((i.results[month.value] ?? null) === next) delete draft[i.id]
  else draft[i.id] = next
  if (next) prefillMonth()
}
function markAllNormal() {
  let n = 0
  for (const i of data.value?.items ?? []) if (value(i) === null) { draft[i.id] = 'normal'; n++ }
  if (n) prefillMonth()
  notice.value = n ? `ทำเครื่องหมายปกติ ${n} รายการ — กดบันทึกเพื่อยืนยัน` : 'ทุกรายการมีผลตรวจแล้ว'
}
function undoDraft() { clearDraft(); resetMonthForm(); notice.value = '' }

async function save() {
  if (!data.value || !dirty.value || saving.value) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const d = await api.post<InspectionYear>(`/admin/inspections/${data.value.year}/months/${month.value}`, {
      results: { ...draft }, inspected_on: monthForm.inspected_on || null, inspector: monthForm.inspector, reviewer: monthForm.reviewer, notes: monthForm.notes,
    })
    apply(d)
    notice.value = `บันทึกผลตรวจเดือน ${inspectionMonths[month.value - 1]} ${d.year} แล้ว`
  } catch (e) {
    error.value = e instanceof ApiError && Object.keys(e.errors).length ? Object.values(e.errors).join(' · ') : (e as Error).message
  } finally { saving.value = false }
}

/* ---------- items ---------- */
const itemForm = reactive({ id: 0, asset_code: '', item: '', notes: '' })
const itemOpen = ref(false), itemErrors = ref<Record<string, string>>({}), itemBusy = ref(false)
function openItem(i?: InspectionItem) {
  if (i && i.id in draft) { error.value = 'บันทึกหรือยกเลิกผลตรวจที่แก้ไว้ก่อนแก้ไขรายการ'; return }
  Object.assign(itemForm, { id: i?.id ?? 0, asset_code: i?.asset_code ?? '', item: i?.item ?? '', notes: i?.notes ?? '' })
  itemErrors.value = {}; itemOpen.value = true
}
const editingItem = computed(() => data.value?.items.find(i => i.id === itemForm.id) ?? null)
const editingHasResults = computed(() => !!editingItem.value && Object.keys(editingItem.value.results).length > 0)
async function saveItem() {
  if (!data.value || itemBusy.value) return
  itemBusy.value = true; itemErrors.value = {}
  try {
    const body = { asset_code: itemForm.asset_code, item: itemForm.item, notes: itemForm.notes }
    const d = itemForm.id ? await api.put<InspectionYear>(`/admin/inspections/items/${itemForm.id}`, body) : await api.post<InspectionYear>(`/admin/inspections/${data.value.year}/items`, body)
    keepDraftApply(d); itemOpen.value = false
    notice.value = itemForm.id ? 'แก้ไขรายการแล้ว' : 'เพิ่มรายการแล้ว'
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) itemErrors.value = e.errors
    else itemErrors.value = { _: (e as Error).message }
  } finally { itemBusy.value = false }
}
async function deleteItem() {
  if (!editingItem.value || itemBusy.value || !window.confirm(`ลบรายการ ${editingItem.value.asset_code || inspectionItemName(editingItem.value)} ออกจากใบตรวจสอบปี ${data.value?.year}?`)) return
  itemBusy.value = true
  try { keepDraftApply(await api.del<InspectionYear>(`/admin/inspections/items/${itemForm.id}`)); itemOpen.value = false; notice.value = 'ลบรายการแล้ว' }
  catch (e) { itemErrors.value = { _: (e as Error).message } }
  finally { itemBusy.value = false }
}
/** Item edits keep unsaved marks of other rows and the month form as they are. */
function keepDraftApply(d: InspectionYear) {
  const marks = { ...draft }, form = { ...monthForm }
  apply(d)
  for (const [k, v] of Object.entries(marks)) if (d.items.some(i => i.id === Number(k))) draft[Number(k)] = v
  Object.assign(monthForm, form)
}

/* ---------- bulk add ---------- */
const bulkOpen = ref(false), bulkBusy = ref(false), bulkError = ref('')
const bulk = reactive({ source: 'year' as 'year' | 'assets', from_year: 0, company: '' })
function openBulk() {
  bulk.from_year = otherYears.value[0]?.year ?? 0
  bulk.source = bulk.from_year ? 'year' : 'assets'
  bulkError.value = ''; bulkOpen.value = true
}
async function runBulk() {
  if (!data.value || bulkBusy.value) return
  bulkBusy.value = true; bulkError.value = ''
  try {
    const d = await api.post<InspectionYear>(`/admin/inspections/${data.value.year}/items/bulk`, bulk.source === 'year' ? { source: 'year', from_year: bulk.from_year } : { source: 'assets', company: bulk.company })
    keepDraftApply(d); bulkOpen.value = false
    notice.value = d.added ? `เพิ่ม ${d.added} รายการแล้ว` : 'ไม่มีรายการใหม่ (มีครบแล้ว)'
  } catch (e) { bulkError.value = (e as Error).message }
  finally { bulkBusy.value = false }
}

/* ---------- import / print ---------- */
const importOpen = ref(false)
function openImport() { if (confirmDiscard()) { undoDraft(); importOpen.value = true } }
function imported(year: number) { load(year || data.value?.year, false) }
const printRef = ref<InstanceType<typeof InspectionPrint> | null>(null)
async function print() {
  if (!data.value || printing.value || dirty.value) return
  printing.value = true; error.value = ''
  try { await nextTick(); await printRef.value?.prepare(); window.print() }
  catch (e) { error.value = (e as Error).message }
  finally { printing.value = false }
}

// Captured before the first load rewrites the query (?import=1 from the documents page).
const importOpenRequested = route.query.import === '1'
const beforeUnload = (e: BeforeUnloadEvent) => { if (dirty.value) { e.preventDefault(); e.returnValue = '' } }
onBeforeRouteLeave(() => confirmDiscard())
onMounted(async () => {
  window.addEventListener('beforeunload', beforeUnload)
  const m = Number(route.query.month)
  if (m >= 1 && m <= 12) month.value = m
  await load(typeof route.query.year === 'string' ? route.query.year : undefined, m >= 1 && m <= 12)
  if (importOpenRequested) importOpen.value = true
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
const statusLabel: Record<string, string> = { repair: 'ส่งซ่อม', spare: 'สำรอง', retired: 'เลิกใช้' }
</script>

<template>
  <div class="no-print">
    <RouterLink :to="{ name: 'documents' }" class="text-sm text-brand-700 dark:text-brand-300">← เอกสารฝ่าย IT</RouterLink>
    <div class="mt-2 flex flex-wrap items-start gap-3">
      <div class="min-w-0 flex-1">
        <p class="font-mono text-xs font-semibold text-brand-700 dark:text-brand-300">FM-IT-002</p>
        <h1 class="text-2xl font-bold">ใบตรวจสอบโครงสร้างพื้นฐาน</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">หนึ่งใบต่อปี เลือกเดือนแล้วกดผลตรวจของแต่ละเครื่องได้ทันที ไม่ต้องสร้างเอกสารใหม่</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" @click="openImport"><Icon name="upload" :size="16" /> Import Excel</button>
        <button class="btn-primary" :disabled="!data?.items.length || printing || dirty" :title="dirty ? 'บันทึกผลตรวจก่อนพิมพ์' : ''" @click="print"><Icon name="printer" :size="16" /> {{ printing ? 'กำลังเตรียม...' : `พิมพ์ใบตรวจสอบปี ${data?.year ?? ''}` }}</button>
      </div>
    </div>

    <p v-if="error" role="alert" class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{ error }}</p>
    <p v-if="loading && !data" class="mt-6 text-slate-500">กำลังโหลด...</p>

    <template v-if="data">
      <div class="mt-5 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-sm font-medium">ปี พ.ศ.
          <select class="input w-28" :value="data.year" :disabled="loading || saving" aria-label="ปี พ.ศ." @change="pickYear">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </label>
        <span class="text-sm text-slate-500 dark:text-slate-400">{{ data.items.length }} รายการ</span>
      </div>

      <section v-if="!data.items.length" class="card mt-4 p-5">
        <h2 class="font-semibold">ยังไม่มีรายการตรวจสอบของปี {{ data.year }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">เริ่มใบตรวจสอบปีนี้โดยคัดลอกรายการจากปีก่อน ดึงอุปกรณ์จากทะเบียน หรือ Import Excel</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button class="btn-primary" @click="openBulk"><Icon name="plus" :size="16" /> เริ่มใบตรวจสอบปี {{ data.year }}</button>
          <button class="btn-secondary" @click="openItem()">เพิ่มทีละรายการ</button>
        </div>
      </section>

      <template v-else>
        <div class="mt-4 grid grid-cols-4 gap-2 sm:grid-cols-6 lg:grid-cols-12" role="group" aria-label="เลือกเดือน">
          <button v-for="(m, k) in inspectionMonths" :key="m" type="button" :aria-pressed="month === k + 1"
            :class="['rounded-xl border px-1 py-2 text-center text-sm transition', month === k + 1 ? 'border-brand-600 bg-brand-600 text-white' : monthStats[k].done === monthStats[k].total ? 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300' : monthStats[k].done ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700']"
            @click="pickMonth(k + 1)">
            <span class="block font-semibold">{{ m }}</span>
            <span class="block text-xs opacity-80">{{ monthStats[k].done }}/{{ monthStats[k].total }}<template v-if="monthStats[k].bad"> · X{{ monthStats[k].bad }}</template></span>
          </button>
        </div>

        <section class="card mt-4 p-4">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold">ผลตรวจเดือน {{ inspectionMonths[month - 1] }} {{ data.year }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">ตรวจแล้ว {{ current.done }}/{{ current.total }}<template v-if="current.bad"> · <span class="text-red-600 dark:text-red-400">ผิดปกติ {{ current.bad }}</span></template><template v-if="savedMonth?.updated_by_name"> · บันทึกล่าสุดโดย {{ savedMonth.updated_by_name }} {{ fmtDateTime(savedMonth.updated_at) }}</template></p>
          </div>
          <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block"><span class="label">วันที่ตรวจ</span><input v-model="monthForm.inspected_on" type="date" class="input" /></label>
            <label class="block"><span class="label">ผู้ตรวจสอบ</span><input v-model="monthForm.inspector" maxlength="100" class="input" list="inspection-names" /></label>
            <label class="block"><span class="label">ผู้ทบทวน</span><input v-model="monthForm.reviewer" maxlength="100" class="input" list="inspection-names" /></label>
            <label class="block"><span class="label">หมายเหตุประจำเดือน</span><input v-model="monthForm.notes" maxlength="500" class="input" /></label>
          </div>
          <datalist id="inspection-names"><option v-for="n in data.names" :key="n" :value="n" /></datalist>
          <div class="mt-4 flex flex-wrap items-center gap-2">
            <button class="btn-secondary" :disabled="saving" @click="markAllNormal"><Icon name="check" :size="16" /> ทุกรายการที่ยังว่าง = ปกติ</button>
            <span class="flex-1" />
            <span v-if="dirty" class="text-sm text-amber-700 dark:text-amber-300">ยังไม่บันทึก{{ draftCount ? ` ${draftCount} รายการ` : '' }}</span>
            <button v-if="dirty" class="btn-ghost" :disabled="saving" @click="undoDraft">ยกเลิกการแก้ไข</button>
            <button class="btn-primary" :disabled="!dirty || saving" @click="save">{{ saving ? 'กำลังบันทึก...' : `บันทึกผลเดือน ${inspectionMonths[month - 1]}` }}</button>
          </div>
          <p v-if="notice" role="status" class="mt-3 rounded-xl bg-green-50 dark:bg-green-900/40 p-3 text-sm text-green-800 dark:text-green-300">{{ notice }}</p>
        </section>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <input v-model="q" class="input min-w-48 flex-1" placeholder="ค้นหาหมายเลขเครื่อง / รายการ / ผู้ใช้ / หน่วยงาน" aria-label="ค้นหารายการ" />
          <button class="btn-secondary" @click="openItem()"><Icon name="plus" :size="16" /> เพิ่มรายการ</button>
          <button class="btn-secondary" @click="openBulk">เพิ่มหลายเครื่อง</button>
        </div>
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">กดเครื่องหมายเพื่อบันทึกผล กดซ้ำเพื่อล้าง · / ปกติ · X ผิดปกติ · ✓ แก้ไขแล้ว</p>

        <div class="table-wrap mt-2">
          <table class="table">
            <thead><tr>
              <th class="w-10">#</th><th>หมายเลขเครื่อง / รายการ</th><th class="hidden md:table-cell">ผู้ใช้ / หน่วยงาน</th>
              <th>ผล {{ inspectionMonths[month - 1] }}</th>
              <th class="hidden xl:table-cell"><span class="grid grid-cols-12 gap-0.5 text-center text-[10px] font-normal"><span v-for="(m, k) in inspectionMonths" :key="m" :class="k + 1 === month ? 'font-bold text-brand-700 dark:text-brand-300' : ''">{{ m.replace('.', '').slice(0, 2) }}</span></span></th>
              <th class="hidden lg:table-cell">หมายเหตุ</th><th class="w-12" aria-label="แก้ไข"></th>
            </tr></thead>
            <tbody>
              <tr v-for="item in shown" :key="item.id" :class="item.id in draft ? 'bg-amber-50/60 dark:bg-amber-900/20' : ''">
                <td class="text-slate-500">{{ data.items.indexOf(item) + 1 }}</td>
                <td>
                  <RouterLink v-if="item.asset_id" :to="{ name: 'asset-detail', params: { id: item.asset_id } }" class="font-mono font-semibold text-brand-700 dark:text-brand-300">{{ item.asset_code }}</RouterLink>
                  <span v-else class="font-mono font-semibold">{{ item.asset_code || '—' }}</span>
                  <span v-if="item.asset_code && !item.asset_id" class="ml-1 rounded bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5 text-[11px] text-slate-600 dark:text-slate-300">ไม่พบในทะเบียน</span>
                  <span v-else-if="item.asset && item.asset.code !== item.asset_code" class="ml-1 rounded bg-amber-100 dark:bg-amber-900/50 px-1.5 py-0.5 text-[11px] text-amber-800 dark:text-amber-300">ปัจจุบัน {{ item.asset.code }}</span>
                  <span v-if="item.asset && statusLabel[item.asset.status]" class="ml-1 rounded bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5 text-[11px]">{{ statusLabel[item.asset.status] }}</span>
                  <div class="text-xs text-slate-500 dark:text-slate-400">{{ [inspectionItemName(item), item.asset?.model].filter(Boolean).join(' · ') || '—' }}</div>
                </td>
                <td class="hidden md:table-cell text-xs text-slate-600 dark:text-slate-300">{{ item.asset?.holder || '—' }}<div class="text-slate-500 dark:text-slate-400">{{ item.asset?.department || '' }}</div></td>
                <td>
                  <div class="flex gap-1" role="group" :aria-label="`ผลตรวจ ${item.asset_code || inspectionItemName(item)}`">
                    <button v-for="r in results" :key="r" type="button" :aria-pressed="value(item) === r" :title="inspectionLabels[r]" :aria-label="inspectionLabels[r]"
                      :class="['h-9 w-9 rounded-lg border text-base font-bold transition', value(item) === r ? markTone[r] : 'border-slate-300 text-slate-500 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-400 dark:hover:bg-slate-700']"
                      :disabled="saving" @click="setMark(item, r)">{{ inspectionMarks[r] }}</button>
                  </div>
                </td>
                <td class="hidden xl:table-cell"><span class="grid grid-cols-12 gap-0.5 text-center font-mono text-xs"><span v-for="m in 12" :key="m" :class="[m === month ? 'rounded bg-brand-50 dark:bg-brand-900/40' : '', (m === month ? value(item) : item.results[m]) ? miniTone[(m === month ? value(item) : item.results[m])!] : 'text-slate-300 dark:text-slate-600']">{{ (m === month ? value(item) : item.results[m]) ? inspectionMarks[(m === month ? value(item) : item.results[m])!] : '·' }}</span></span></td>
                <td class="hidden lg:table-cell max-w-56 text-xs text-slate-600 dark:text-slate-300">{{ item.notes || '' }}</td>
                <td><button class="btn-ghost p-2" :aria-label="`แก้ไขรายการ ${item.asset_code || inspectionItemName(item)}`" @click="openItem(item)"><Icon name="edit" :size="16" /></button></td>
              </tr>
              <tr v-if="!shown.length"><td colspan="7" class="py-6 text-center text-slate-500">ไม่พบรายการที่ค้นหา</td></tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>

  <Modal v-if="itemOpen" :title="itemForm.id ? 'แก้ไขรายการตรวจสอบ' : `เพิ่มรายการตรวจสอบปี ${data?.year}`" @close="itemOpen = false">
    <form class="space-y-3" @submit.prevent="saveItem">
      <label class="block"><span class="label">หมายเลขเครื่อง</span><input v-model="itemForm.asset_code" maxlength="30" class="input font-mono uppercase" :class="{ 'input-error': itemErrors.asset_code }" placeholder="เช่น PD-01-001" />
        <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">ถ้าตรงกับทะเบียนอุปกรณ์ ระบบจะเชื่อมรุ่น / ผู้ใช้ / หน่วยงานให้เอง</span>
        <span v-if="itemErrors.asset_code" class="mt-1 block text-sm text-red-600">{{ itemErrors.asset_code }}</span></label>
      <label class="block"><span class="label">รายการตรวจสอบ</span><input v-model="itemForm.item" maxlength="150" class="input" placeholder="เว้นว่างเพื่อใช้หมวดจากทะเบียน เช่น Notebook" />
        <span v-if="itemErrors.item" class="mt-1 block text-sm text-red-600">{{ itemErrors.item }}</span></label>
      <label class="block"><span class="label">หมายเหตุ</span><input v-model="itemForm.notes" maxlength="500" class="input" /></label>
      <p v-if="itemErrors._" role="alert" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ itemErrors._ }}</p>
      <div class="flex flex-wrap items-center gap-2 pt-2">
        <button v-if="itemForm.id" type="button" class="btn-danger" :disabled="itemBusy || editingHasResults" :title="editingHasResults ? 'มีผลตรวจบันทึกไว้แล้ว ลบไม่ได้' : ''" @click="deleteItem"><Icon name="trash" :size="16" /> ลบ</button>
        <span v-if="itemForm.id && editingHasResults" class="text-xs text-slate-500 dark:text-slate-400">มีผลตรวจแล้ว ลบไม่ได้</span>
        <span class="flex-1" />
        <button type="button" class="btn-secondary" @click="itemOpen = false">ยกเลิก</button>
        <button class="btn-primary" :disabled="itemBusy">{{ itemBusy ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
      </div>
    </form>
  </Modal>

  <Modal v-if="bulkOpen" :title="`เพิ่มรายการหลายเครื่อง ปี ${data?.year}`" @close="bulkOpen = false">
    <form class="space-y-3" @submit.prevent="runBulk">
      <label class="flex items-start gap-2"><input v-model="bulk.source" type="radio" value="year" class="mt-1" :disabled="!otherYears.length" />
        <span>คัดลอกรายการจากปี
          <select v-model.number="bulk.from_year" class="input mt-1 w-32" :disabled="bulk.source !== 'year' || !otherYears.length"><option v-for="y in otherYears" :key="y.year" :value="y.year">{{ y.year }} ({{ y.items }})</option></select>
          <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">คัดลอกเฉพาะรายการ ไม่คัดลอกผลตรวจ{{ otherYears.length ? '' : ' (ยังไม่มีปีอื่น)' }}</span></span></label>
      <label class="flex items-start gap-2"><input v-model="bulk.source" type="radio" value="assets" class="mt-1" />
        <span>ดึงอุปกรณ์จากทะเบียน (ใช้งาน / สำรอง / ส่งซ่อม ไม่รวมเลิกใช้)
          <select v-model="bulk.company" class="input mt-1 w-40" :disabled="bulk.source !== 'assets'"><option value="">ทุกบริษัท</option><option value="ATS">ATS</option><option value="INNO">INNO</option></select></span></label>
      <p class="text-xs text-slate-500 dark:text-slate-400">หมายเลขเครื่องที่มีอยู่แล้วในปีนี้จะถูกข้าม</p>
      <p v-if="bulkError" role="alert" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ bulkError }}</p>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn-secondary" @click="bulkOpen = false">ยกเลิก</button>
        <button class="btn-primary" :disabled="bulkBusy || (bulk.source === 'year' && !bulk.from_year)">{{ bulkBusy ? 'กำลังเพิ่ม...' : 'เพิ่มรายการ' }}</button>
      </div>
    </form>
  </Modal>

  <InspectionImport v-if="importOpen" @close="importOpen = false" @imported="imported" />
  <InspectionPrint v-if="data" ref="printRef" :data="data" />
</template>
