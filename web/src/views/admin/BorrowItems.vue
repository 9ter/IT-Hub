<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '@/api'
import { fmtFormDate } from '@/format'
import { companyLogo, ensureCompanies } from '@/companies'
import '@fontsource/noto-serif-thai/400.css'
import '@fontsource/noto-serif-thai/700.css'

interface BorrowItem {
  document_id: number; document_no: string; document_date: string; company: string; document_status: string; ticket_no: string | null; line: number
  start_date: string | null; end_date: string | null; department: string | null; user: string | null; purpose: string | null; device_kind: string | null
  item: string | null; quantity: string | null; serial: string | null; date: string | null; issuer: string | null; head: string | null
  returned_at: string | null; returner: string | null; receiver: string | null; return_condition: string | null
  state: 'outstanding' | 'returned' | 'cancelled'; overdue: boolean
}
interface BorrowItems {
  data: BorrowItem[]; total: number; truncated: boolean; documents: number; today: string; cancelled_documents: number
  counts: { all: number; outstanding: number; overdue: number; returned: number }
}

const result = ref<BorrowItems | null>(null)
const q = ref(''), state = ref(''), documents = ref('active'), error = ref(''), loading = ref(false), printing = ref(false)
const printedAt = ref('')
const kinds: Record<string, string> = { notebook: 'Notebook', other: 'อื่นๆ' }
const states = [{ key: '', label: 'ทั้งหมด', count: 'all' }, { key: 'outstanding', label: 'ยืมอยู่', count: 'outstanding' }, { key: 'overdue', label: 'เกินกำหนดคืน', count: 'overdue' }, { key: 'returned', label: 'คืนแล้ว', count: 'returned' }] as const
const docOptions: Record<string, string> = { active: 'ไม่รวมบันทึกที่ยกเลิก', all: 'รวมบันทึกที่ยกเลิก', cancelled: 'เฉพาะบันทึกที่ยกเลิก' }

async function load() {
  loading.value = true; error.value = ''
  try { result.value = await api.get<BorrowItems>('/admin/borrow-items', { q: q.value, state: state.value, documents: documents.value }) }
  catch (e) { error.value = (e as Error).message }
  finally { loading.value = false }
}
function pick(key: string) { state.value = key; load() }
onMounted(load)

const d = (s: string | null) => fmtFormDate(s) || ''
const stateLabel = (i: BorrowItem) => i.state === 'cancelled' ? 'ยกเลิก' : i.state === 'returned' ? 'คืนแล้ว' : i.overdue ? 'เกินกำหนด' : 'ยืมอยู่'
const stateTone = (i: BorrowItem) => i.state === 'cancelled' ? 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' : i.state === 'returned' ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' : i.overdue ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300'
// รายงานรวมหลายบันทึก: ถ้าทุกแถวเป็นบริษัทเดียวกันใช้ชื่อ/โลโก้ของบริษัทนั้น ไม่อย่างนั้นใช้ ATS ตามเดิม
const reportCompany = computed(() => { const names = new Set((result.value?.data || []).map((i) => i.company)); return names.size === 1 ? [...names][0] : null })
const reportLogo = computed(() => companyLogo(reportCompany.value))
const totalQuantity = computed(() => (result.value?.data || []).reduce((sum, i) => sum + (Number(i.quantity) || 0), 0))
const filterText = computed(() => [states.find(s => s.key === state.value)?.label || 'ทั้งหมด', docOptions[documents.value], q.value.trim() ? `ค้นหา “${q.value.trim()}”` : ''].filter(Boolean).join(' · '))

async function print() {
  if (printing.value || loading.value || !result.value) return
  printing.value = true; error.value = ''
  try {
    printedAt.value = new Intl.DateTimeFormat('th-TH', { dateStyle: 'short', timeStyle: 'short' }).format(new Date())
    await ensureCompanies()
    await nextTick()
    await Promise.all([document.fonts.load('8pt "Noto Serif Thai"', 'รายการเบิก ABC 0123'), document.fonts.load('700 12pt "Noto Serif Thai"', 'รายการเบิก ABC')])
    await Promise.all(Array.from(document.querySelectorAll<HTMLImageElement>('.borrow-items-print img')).map(img => img.decode().catch(() => {})))
    window.print()
  } catch (e) { error.value = (e as Error).message }
  finally { printing.value = false }
}
</script>

<template>
  <div class="no-print">
    <RouterLink :to="{ name: 'borrow-records' }" class="text-sm text-brand-700 dark:text-brand-300">← บันทึกเบิก–ยืมอุปกรณ์</RouterLink>
    <div class="mt-2 flex flex-wrap items-start gap-3">
      <div class="min-w-0 flex-1">
        <h1 class="text-2xl font-bold">รวมทุกรายการเบิก–ยืม</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">อุปกรณ์ทุกรายการจากบันทึก FM-IT-008 ในตารางเดียว ใช้ตรวจของที่ยังไม่คืนและพิมพ์สรุปได้</p>
      </div>
      <button class="btn-primary" :disabled="printing || loading || !result?.data.length" @click="print">{{ printing ? 'กำลังเตรียม...' : 'พิมพ์รายการ' }}</button>
    </div>

    <div class="mt-5 flex flex-wrap gap-2" role="group" aria-label="สถานะการคืน">
      <button v-for="s in states" :key="s.key" type="button" :aria-pressed="state === s.key"
        :class="['rounded-full border px-3 py-1.5 text-sm', state === s.key ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700']"
        @click="pick(s.key)">{{ s.label }} <span class="ml-1 opacity-80">{{ result?.counts[s.count] ?? '' }}</span></button>
    </div>

    <form class="card mt-4 flex flex-wrap gap-3 p-4" @submit.prevent="load()">
      <input v-model="q" aria-label="ค้นหารายการ" placeholder="ผู้ยืม / แผนก / อุปกรณ์ / Serial / เลขที่" class="input min-w-48 flex-1" />
      <select v-model="documents" class="input sm:w-56" aria-label="บันทึกที่ยกเลิก" @change="load()"><option v-for="(label, key) in docOptions" :key="key" :value="key">{{ label }}</option></select>
      <button class="btn-primary" :disabled="loading">ค้นหา</button>
    </form>

    <p v-if="error" role="alert" class="mt-3 rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{ error }}</p>
    <p v-if="result?.truncated" class="mt-3 rounded-xl bg-amber-50 dark:bg-amber-900/40 p-3 text-sm text-amber-800 dark:text-amber-300">แสดง {{ result.data.length.toLocaleString() }} จาก {{ result.total.toLocaleString() }} รายการ กรุณาค้นหาให้แคบลง</p>

    <div class="card table-wrap mt-4">
      <table class="table">
        <thead><tr><th>เลขที่</th><th>ผู้ยืม / แผนก</th><th>อุปกรณ์</th><th>จำนวน</th><th>วันที่ใช้ / กำหนดคืน</th><th>การคืน</th><th>สถานะ</th></tr></thead>
        <tbody>
          <tr v-for="i in result?.data" :key="`${i.document_id}-${i.line}`">
            <td><RouterLink :to="{ name: 'document-detail', params: { id: i.document_id } }" class="whitespace-nowrap font-mono text-brand-700 dark:text-brand-300">{{ i.document_no }}</RouterLink><p class="text-xs text-slate-400">รายการที่ {{ i.line }}<template v-if="i.ticket_no"> · {{ i.ticket_no }}</template></p></td>
            <td>{{ i.user || '-' }}<p class="text-xs text-slate-500 dark:text-slate-400">{{ i.department }}</p></td>
            <td>{{ i.item || '-' }}<p class="text-xs text-slate-500 dark:text-slate-400"><template v-if="i.device_kind">{{ kinds[i.device_kind] || i.device_kind }}</template><template v-if="i.serial"> · S/N {{ i.serial }}</template></p></td>
            <td class="text-center">{{ i.quantity }}</td>
            <td class="whitespace-nowrap">{{ d(i.date || i.start_date) || '-' }}<p class="text-xs" :class="i.overdue ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">คืน {{ d(i.end_date) || 'ไม่กำหนด' }}</p></td>
            <td><template v-if="i.returned_at">{{ d(i.returned_at) }}<p class="text-xs text-slate-500 dark:text-slate-400">{{ [i.returner, i.receiver && `รับ: ${i.receiver}`].filter(Boolean).join(' · ') }}</p></template><span v-else class="text-slate-400">-</span></td>
            <td><span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', stateTone(i)]">{{ stateLabel(i) }}</span></td>
          </tr>
          <tr v-if="!result?.data.length"><td colspan="7" class="py-8 text-center text-slate-400">
            {{ loading ? 'กำลังโหลด...' : 'ไม่มีรายการ' }}
            <p v-if="!loading && documents === 'active' && result?.cancelled_documents" class="mt-1 text-xs">มีบันทึกที่ยกเลิก {{ result.cancelled_documents }} ฉบับ เลือก “รวมบันทึกที่ยกเลิก” เพื่อดู</p>
          </td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="result" class="mt-3 text-sm">ทั้งหมด {{ result.total.toLocaleString() }} รายการ จาก {{ result.documents }} บันทึก · จำนวนอุปกรณ์รวม {{ totalQuantity.toLocaleString() }}</p>
  </div>

  <div v-if="result" class="borrow-items-print">
    <header class="bi-header"><img v-if="reportLogo" :src="reportLogo" :alt="reportCompany || 'Atthasan'" /><div><h1>สรุปรายการเบิก–ยืมอุปกรณ์ IT (รวมทุกรายการ)</h1><p>จากบันทึก FM-IT-008 แบบฟอร์มใบเบิก-คืนอุปกรณ์ IT</p></div></header>
    <div class="bi-meta"><span>เงื่อนไข: {{ filterText }}</span><span>ทั้งหมด {{ result.total }} รายการ · {{ result.documents }} บันทึก · จำนวนรวม {{ totalQuantity }}</span><span>พิมพ์เมื่อ {{ printedAt }}</span></div>
    <table class="bi-table">
      <colgroup><col style="width:10mm" /><col style="width:26mm" /><col style="width:20mm" /><col style="width:20mm" /><col /><col style="width:11mm" /><col style="width:20mm" /><col style="width:17mm" /><col style="width:17mm" /><col style="width:18mm" /><col style="width:17mm" /><col style="width:18mm" /><col style="width:20mm" /><col style="width:16mm" /></colgroup>
      <thead><tr><th>ลำดับ</th><th>เลขที่บันทึก</th><th>ฝ่าย / แผนก</th><th>ผู้ขอใช้</th><th>อุปกรณ์ / วัตถุประสงค์</th><th>จำนวน</th><th>Serial No.</th><th>วันที่ใช้</th><th>กำหนดคืน</th><th>IT ผู้จ่าย</th><th>วันที่คืน</th><th>ผู้คืน / IT ผู้รับ</th><th>สภาพเมื่อคืน</th><th>สถานะ</th></tr></thead>
      <tbody>
        <tr v-for="(i, n) in result.data" :key="`${i.document_id}-${i.line}`">
          <td class="c">{{ n + 1 }}</td><td>{{ i.document_no }}<br /><small>รายการที่ {{ i.line }}</small></td><td>{{ i.department }}</td><td>{{ i.user }}</td>
          <td><template v-if="i.device_kind">{{ kinds[i.device_kind] || i.device_kind }}: </template>{{ i.item }}<template v-if="i.purpose"><br /><small>{{ i.purpose }}</small></template></td>
          <td class="c">{{ i.quantity }}</td><td>{{ i.serial }}</td><td class="nw">{{ d(i.date || i.start_date) }}</td><td class="nw">{{ d(i.end_date) }}</td><td>{{ i.issuer }}</td><td class="nw">{{ d(i.returned_at) }}</td>
          <td>{{ i.returner }}<template v-if="i.receiver"><br /><small>รับ: {{ i.receiver }}</small></template></td><td>{{ i.return_condition }}</td><td class="c">{{ stateLabel(i) }}</td>
        </tr>
      </tbody>
    </table>
    <footer>รายงานสรุปจากระบบ IT Hub · ไม่ใช่แบบฟอร์มลงนาม (พิมพ์ใบ FM-IT-008 รายฉบับได้จากหน้าบันทึก)<span>{{ printedAt }}</span></footer>
  </div>
</template>

<style scoped>
.borrow-items-print{display:none;color:#000;font-family:'Noto Serif Thai',serif;font-size:7.5pt;line-height:1.4}
.bi-header{display:flex;align-items:center;gap:5mm;margin-bottom:2mm}.bi-header img{width:22mm;height:16mm;object-fit:contain}.bi-header>div{flex:1;text-align:center}.bi-header h1{font-size:12pt;font-weight:700;margin:0}.bi-header p{font-size:8.5pt;margin:1mm 0 0}
.bi-meta{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1mm 4mm;font-size:8pt;border-bottom:.3mm solid #000;padding-bottom:1.5mm;margin-bottom:2mm}
.bi-table{width:100%;border-collapse:collapse;table-layout:fixed}.bi-table th,.bi-table td{border:.2mm solid #000;padding:.8mm 1mm;vertical-align:top;overflow-wrap:anywhere;white-space:pre-wrap}
.bi-table th{background:#f1f5f9;font-weight:700;text-align:center}.bi-table thead{display:table-header-group}.bi-table tr{break-inside:avoid}.bi-table small{font-size:6.5pt;color:#333}.c{text-align:center}.bi-table .nw{white-space:nowrap}.bi-table th{padding:.8mm .5mm}
footer{display:flex;justify-content:space-between;gap:3mm;border-top:.2mm solid #000;margin-top:3mm;padding-top:1.5mm;font-size:7pt}
@page borrowItems{size:A4 landscape;margin:10mm 10mm 12mm}
@media print{.borrow-items-print{display:block;page:borrowItems;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
