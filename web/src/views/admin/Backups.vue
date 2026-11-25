<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api'
import { fmtDateTime, fmtFormDate, todayInput } from '@/format'
import { toast } from '@/store'
import { backupPrintDocument, type BackupRow, type BackupSuggestKey, type BackupYear } from '@/backups'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import DocumentPrint from '@/components/DocumentPrint.vue'
import BackupImport from '@/components/BackupImport.vue'

const route = useRoute(), router = useRouter()
const data = ref<BackupYear | null>(null)
const loading = ref(false), error = ref(''), q = ref(''), server = ref('')
const printing = ref(false), printRef = ref<InstanceType<typeof DocumentPrint> | null>(null)
const importOpen = ref(false)

const yearOptions = computed(() => {
  if (!data.value) return []
  const set = new Set([...data.value.years.map(y => y.year), data.value.current_year, data.value.year])
  return [...set].sort((a, b) => b - a)
})
const yearCount = (y: number) => data.value?.years.find(x => x.year === y)?.count ?? 0
const serverRows = computed(() => (data.value?.rows ?? []).filter(r => !server.value || r.server === server.value))
const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  if (!term) return serverRows.value
  return serverRows.value.filter(r => [fmtFormDate(r.backup_date), r.server, r.dataset, r.data_size, r.source, r.device, r.folder, r.backup_size, r.maker, r.acknowledger, r.notes]
    .some(v => v?.toLowerCase().includes(term)))
})
const latest = computed(() => serverRows.value.length ? serverRows.value[serverRows.value.length - 1] : null)
const printServer = computed(() => server.value || (data.value?.servers.length === 1 ? data.value.servers[0] : ''))
const printDocument = computed(() => data.value && printServer.value ? backupPrintDocument(data.value.year, printServer.value, serverRows.value, data.value.form) : null)

async function load(year?: number | string) {
  loading.value = true; error.value = ''
  try {
    const d = await api.get<BackupYear>('/admin/backups', year ? { year: String(year) } : undefined)
    data.value = d
    if (server.value && !d.servers.includes(server.value)) server.value = ''
    router.replace({ query: { ...route.query, year: String(d.year), import: undefined } })
  } catch (e) { error.value = (e as Error).message }
  finally { loading.value = false }
}
function pickYear(e: Event) { load((e.target as HTMLSelectElement).value) }

// --- add / edit
type FormKey = 'backup_date' | 'server' | 'dataset' | 'data_size' | 'source' | 'device' | 'folder' | 'backup_size' | 'started_at' | 'finished_at' | 'maker' | 'acknowledger' | 'notes'
const blank = (): Record<FormKey, string> => ({ backup_date: '', server: '', dataset: '', data_size: '', source: '', device: '', folder: '', backup_size: '', started_at: '', finished_at: '', maker: '', acknowledger: '', notes: '' })
const form = reactive(blank())
const editing = ref<BackupRow | null>(null), formOpen = ref(false), saving = ref(false)
const formErrors = ref<Record<string, string>>({}), formError = ref('')
/** Backup size follows the real size until the admin types a different one. */
const sizeLinked = ref(true)
function openForm(row?: BackupRow) {
  Object.assign(form, blank()); formErrors.value = {}; formError.value = ''
  editing.value = row ?? null
  const src = row ?? data.value?.last ?? null
  if (src) for (const k of ['server', 'dataset', 'source', 'device', 'folder', 'maker', 'acknowledger'] as const) form[k] = src[k] ?? ''
  if (row) {
    Object.assign(form, { backup_date: row.backup_date, data_size: row.data_size ?? '', backup_size: row.backup_size ?? '', started_at: row.started_at ?? '', finished_at: row.finished_at ?? '', notes: row.notes ?? '' })
    sizeLinked.value = (row.backup_size ?? '') === (row.data_size ?? '')
  } else {
    form.backup_date = todayInput(); form.server ||= server.value || 'SERVER'
    if (data.value?.last?.started_at) form.started_at = data.value.last.started_at.slice(0, 5) + ':00'
    sizeLinked.value = true
  }
  formOpen.value = true
}
function sizeInput() { if (sizeLinked.value) form.backup_size = form.data_size }
function backupSizeInput() { sizeLinked.value = form.backup_size === form.data_size }
async function save() {
  if (saving.value) return
  saving.value = true; formErrors.value = {}; formError.value = ''
  try {
    const body = { ...form }
    const saved = editing.value ? await api.put<BackupRow>(`/admin/backups/${editing.value.id}`, body) : await api.post<BackupRow>('/admin/backups', body)
    formOpen.value = false
    toast(editing.value ? 'บันทึกการแก้ไขแล้ว' : `บันทึกการ Backup ${fmtFormDate(saved.backup_date)} แล้ว`)
    await load(Number(saved.backup_date.slice(0, 4)) + 543)
  } catch (e) {
    const err = e as ApiError
    formErrors.value = err.errors ?? {}; formError.value = Object.keys(formErrors.value).length ? '' : err.message
  } finally { saving.value = false }
}
const closeForm = () => { if (!saving.value) formOpen.value = false }

// --- delete
const deleting = ref<BackupRow | null>(null), deleteBusy = ref(false)
async function confirmDelete() {
  const row = deleting.value
  if (!row || deleteBusy.value) return
  deleteBusy.value = true
  try { await api.del(`/admin/backups/${row.id}`); deleting.value = null; toast(`ลบบันทึก ${fmtFormDate(row.backup_date)} แล้ว`); await load(data.value?.year) }
  catch (e) { toast((e as Error).message, 'error') }
  finally { deleteBusy.value = false }
}

async function print() {
  if (printing.value || !printDocument.value) return
  printing.value = true; error.value = ''
  try { await nextTick(); await printRef.value?.prepare(); window.print() }
  catch (e) { error.value = (e as Error).message }
  finally { printing.value = false }
}

function imported(year: number) { load(year || data.value?.year) }
const suggestId = (k: BackupSuggestKey) => `backup-suggest-${k}`
const fields: { key: FormKey; label: string; max: number; suggest?: BackupSuggestKey; required?: boolean; hint?: string }[] = [
  { key: 'server', label: 'ชื่อ Server', max: 100, suggest: 'server' },
  { key: 'dataset', label: 'ข้อมูลที่ BACK UP', max: 150, suggest: 'dataset', required: true },
  { key: 'source', label: 'แหล่งจัดเก็บข้อมูลหลัก', max: 150, suggest: 'source' },
  { key: 'device', label: 'อุปกรณ์สำรอง', max: 150, suggest: 'device' },
  { key: 'folder', label: 'ชื่อ Folder / File', max: 255, suggest: 'folder' },
]

const importOpenRequested = route.query.import === '1'
onMounted(async () => {
  await load(typeof route.query.year === 'string' ? route.query.year : undefined)
  if (importOpenRequested) importOpen.value = true
})
</script>

<template>
  <div class="no-print">
    <RouterLink :to="{ name: 'documents' }" class="text-sm text-brand-700 dark:text-brand-300">← เอกสารฝ่าย IT</RouterLink>
    <div class="mt-2 flex flex-wrap items-start gap-3">
      <div class="min-w-0 flex-1">
        <p class="font-mono text-xs font-semibold text-brand-700 dark:text-brand-300">FM-IT-005</p>
        <h1 class="text-2xl font-bold">รายงานการ BACK UP ข้อมูล SERVER</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">บันทึกทีละครั้งที่ Backup แก้ไขได้ตลอด พิมพ์รายงานประจำปีตามแบบฟอร์ม ไม่ต้องสร้างเอกสารใหม่</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" @click="importOpen = true"><Icon name="upload" :size="16" /> Import Excel</button>
        <button class="btn-secondary" :disabled="!printDocument || !serverRows.length || printing" :title="!printServer ? 'เลือก Server ก่อนพิมพ์' : ''" @click="print"><Icon name="printer" :size="16" /> {{ printing ? 'กำลังเตรียม...' : `พิมพ์ FM-IT-005 ปี ${data?.year ?? ''}` }}</button>
        <button class="btn-primary" :disabled="!data" @click="openForm()"><Icon name="plus" :size="16" /> บันทึกการ Backup</button>
      </div>
    </div>

    <p v-if="error" role="alert" class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{ error }}</p>
    <p v-if="loading && !data" class="mt-6 text-slate-500">กำลังโหลด...</p>

    <template v-if="data">
      <div class="mt-5 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-sm font-medium">ปี พ.ศ.
          <select class="input w-36" :value="data.year" :disabled="loading" aria-label="ปี พ.ศ." @change="pickYear">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}{{ yearCount(y) ? ` (${yearCount(y)})` : '' }}</option>
          </select>
        </label>
        <label v-if="data.servers.length > 1" class="flex items-center gap-2 text-sm font-medium">Server
          <select v-model="server" class="input w-44" aria-label="Server"><option value="">ทุก Server</option><option v-for="s in data.servers" :key="s" :value="s">{{ s }}</option></select>
        </label>
        <input v-model="q" class="input min-w-48 flex-1" placeholder="ค้นหาวันที่ / ข้อมูล / อุปกรณ์ / ผู้จัดทำ / หมายเหตุ" aria-label="ค้นหาบันทึก Backup" />
      </div>

      <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="card p-4"><p class="text-xs text-slate-500 dark:text-slate-400">บันทึกปี {{ data.year }}</p><p class="mt-1 text-2xl font-bold">{{ serverRows.length }} <span class="text-sm font-normal">ครั้ง</span></p></div>
        <div class="card p-4"><p class="text-xs text-slate-500 dark:text-slate-400">Backup ล่าสุด</p><p class="mt-1 text-lg font-semibold">{{ latest ? fmtFormDate(latest.backup_date) : '—' }}</p><p v-if="latest" class="text-xs text-slate-500 dark:text-slate-400">{{ latest.dataset }} · {{ latest.server }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500 dark:text-slate-400">ขนาดล่าสุด</p><p class="mt-1 text-lg font-semibold">{{ latest?.backup_size || latest?.data_size || '—' }}</p><p v-if="latest?.started_at" class="text-xs text-slate-500 dark:text-slate-400">{{ latest.started_at }} – {{ latest.finished_at || '?' }}</p></div>
      </div>

      <div class="card table-wrap mt-4"><table class="table">
        <thead><tr><th>ว/ด/ป</th><th>ข้อมูลที่ BACK UP</th><th>ขนาดไฟล์จริง</th><th>แหล่งจัดเก็บ → อุปกรณ์สำรอง</th><th>ขนาด BACK UP</th><th>เวลาเริ่ม – เสร็จ</th><th>จัดทำ / รับทราบ</th><th>หมายเหตุ</th><th aria-label="จัดการ"></th></tr></thead>
        <tbody>
          <tr v-for="r in shown" :key="r.id">
            <td class="whitespace-nowrap font-mono">{{ fmtFormDate(r.backup_date) }}</td>
            <td>{{ r.dataset }}<p class="text-xs text-slate-500 dark:text-slate-400">{{ r.server }}</p></td>
            <td class="whitespace-nowrap">{{ r.data_size || '—' }}</td>
            <td>{{ r.source || '—' }} → {{ r.device || '—' }}<p v-if="r.folder" class="text-xs text-slate-500 dark:text-slate-400">{{ r.folder }}</p></td>
            <td class="whitespace-nowrap">{{ r.backup_size || '—' }}</td>
            <td class="whitespace-nowrap font-mono text-sm">{{ r.started_at || '—' }} – {{ r.finished_at || '—' }}</td>
            <td class="whitespace-nowrap">{{ r.maker || '—' }}<p class="text-xs text-slate-500 dark:text-slate-400">{{ r.acknowledger || '—' }}</p></td>
            <td class="max-w-64 whitespace-normal text-sm">{{ r.notes }}</td>
            <td><div class="flex justify-end gap-1">
              <button class="btn-ghost p-2" :title="`แก้ไข ${fmtFormDate(r.backup_date)}`" :aria-label="`แก้ไขบันทึก ${fmtFormDate(r.backup_date)} ${r.dataset}`" @click="openForm(r)"><Icon name="edit" :size="16" /></button>
              <button class="btn-ghost p-2 text-red-600 dark:text-red-400" :aria-label="`ลบบันทึก ${fmtFormDate(r.backup_date)} ${r.dataset}`" @click="deleting = r"><Icon name="trash" :size="16" /></button>
            </div></td>
          </tr>
          <tr v-if="!shown.length"><td colspan="9" class="py-8 text-center text-slate-400">{{ loading ? 'กำลังโหลด...' : q ? 'ไม่พบบันทึกที่ค้นหา' : `ยังไม่มีบันทึก Backup ปี ${data.year}` }}</td></tr>
        </tbody>
      </table></div>
    </template>
  </div>

  <Modal v-if="formOpen" :title="editing ? `แก้ไขบันทึก ${fmtFormDate(editing.backup_date)}` : 'บันทึกการ Backup'" wide @close="closeForm">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block"><span class="label">ว/ด/ป <span class="text-red-600">*</span></span><input v-model="form.backup_date" type="date" required class="input" :aria-invalid="!!formErrors.backup_date" /><span v-if="formErrors.backup_date" class="field-error" role="alert">{{ formErrors.backup_date }}</span></label>
        <label v-for="f in fields" :key="f.key" class="block"><span class="label">{{ f.label }}<span v-if="f.required" class="text-red-600"> *</span></span><input v-model="form[f.key]" :maxlength="f.max" class="input" :list="f.suggest ? suggestId(f.suggest) : undefined" :aria-invalid="!!formErrors[f.key]" /><span v-if="formErrors[f.key]" class="field-error" role="alert">{{ formErrors[f.key] }}</span></label>
        <label class="block"><span class="label">ขนาดไฟล์จริง</span><input v-model="form.data_size" maxlength="40" class="input" placeholder="เช่น 870.45 GB" @input="sizeInput" /><span v-if="formErrors.data_size" class="field-error" role="alert">{{ formErrors.data_size }}</span></label>
        <label class="block"><span class="label">ขนาดไฟล์ BACK UP</span><input v-model="form.backup_size" maxlength="40" class="input" @input="backupSizeInput" /><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ sizeLinked ? 'ตามขนาดไฟล์จริงอัตโนมัติ' : 'ระบุเอง' }}</span></label>
        <label class="block"><span class="label">เวลาเริ่ม</span><input v-model="form.started_at" type="time" step="1" class="input" :aria-invalid="!!formErrors.started_at" /><span v-if="formErrors.started_at" class="field-error" role="alert">{{ formErrors.started_at }}</span></label>
        <label class="block"><span class="label">เวลาเสร็จ</span><input v-model="form.finished_at" type="time" step="1" class="input" :aria-invalid="!!formErrors.finished_at" /><span v-if="formErrors.finished_at" class="field-error" role="alert">{{ formErrors.finished_at }}</span></label>
        <label class="block"><span class="label">จัดทำ</span><input v-model="form.maker" maxlength="100" class="input" :list="suggestId('maker')" /></label>
        <label class="block"><span class="label">รับทราบ</span><input v-model="form.acknowledger" maxlength="100" class="input" :list="suggestId('acknowledger')" /></label>
      </div>
      <label class="block"><span class="label">หมายเหตุ</span><textarea v-model="form.notes" maxlength="500" rows="2" class="input" placeholder="เช่น ไฟดับ ไม่มีการ Backup" /><span v-if="formErrors.notes" class="field-error" role="alert">{{ formErrors.notes }}</span></label>
      <p v-if="!editing && data?.last" class="text-xs text-slate-500 dark:text-slate-400">เติมข้อมูลจากบันทึกล่าสุด ({{ fmtFormDate(data.last.backup_date) }}) ให้แล้ว ตรวจและแก้ขนาด/เวลาของครั้งนี้</p>
      <p v-if="editing?.updated_by_name" class="text-xs text-slate-500 dark:text-slate-400">แก้ไขล่าสุดโดย {{ editing.updated_by_name }} {{ fmtDateTime(editing.updated_at ?? '') }}</p>
      <p v-if="formError" role="alert" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ formError }}</p>
      <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" :disabled="saving" @click="closeForm">ยกเลิก</button><button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}</button></div>
    </form>
    <template v-if="data"><datalist v-for="(list, k) in data.suggest" :id="suggestId(k as BackupSuggestKey)" :key="k"><option v-for="v in list" :key="v" :value="v" /></datalist></template>
  </Modal>

  <Modal v-if="deleting" :title="`ลบบันทึก ${fmtFormDate(deleting.backup_date)}`" @close="deleteBusy || (deleting = null)">
    <p class="text-sm">ลบบันทึก Backup “{{ deleting.dataset }}” ({{ deleting.server }}) วันที่ {{ fmtFormDate(deleting.backup_date) }} ออกจากรายงาน? ลบแล้วกู้คืนไม่ได้</p>
    <div class="mt-4 flex justify-end gap-2"><button class="btn-secondary" :disabled="deleteBusy" @click="deleting = null">ยกเลิก</button><button class="btn-danger" :disabled="deleteBusy" @click="confirmDelete">{{ deleteBusy ? 'กำลังลบ...' : 'ลบบันทึก' }}</button></div>
  </Modal>

  <BackupImport v-if="importOpen" @close="importOpen = false" @imported="imported" />
  <DocumentPrint v-if="data && printDocument" ref="printRef" :key="`${data.year}-${printServer}`" :document="printDocument" :definition="data.form" status-label="" />
</template>
