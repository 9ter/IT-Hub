<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import type { Ticket } from '@/api'
import { fmtFormDate } from '@/format'
import PrintSignatory from './PrintSignatory.vue'
import atsLogo from '@/assets/ats-logo.png'
import '@fontsource/noto-serif-thai/400.css'
import '@fontsource/noto-serif-thai/700.css'

const props = defineProps<{ ticket: Ticket; statusLabel?: string; documentTitle?: string; assistantSignature?: {name:string|null;signature:string|null;signedAt:string|null;rejected?:boolean;note?:string|null} }>()
const root = ref<HTMLElement | null>(null)
const fontReady = ref(false)
const actions = computed(() => Array.isArray(props.ticket.details.actions) ? props.ticket.details.actions as string[] : [])
const selected = (action: string) => actions.value.includes(action)
const field = (key: string) => typeof props.ticket.details[key] === 'string' ? props.ticket.details[key] as string : ''
const requestText = computed(() => [props.documentTitle ? `เรื่อง: ${props.documentTitle}` : '', props.ticket.description || '',
  selected('install') && field('os_target') ? `OS / โปรแกรมที่ต้องการ: ${field('os_target')}` : '',
  selected('other') && field('other_text') ? `อื่นๆ: ${field('other_text')}` : '',
].filter(Boolean).join('\n'))

// Size lines with the same embedded font as the physical table. Oversized text goes to an annex.
function wrapText(text: string): string[] {
  void fontReady.value
  if (!text) return []
  const context = document.createElement('canvas').getContext('2d')!
  context.font = '9pt "Noto Serif Thai"'
  const width = 172 * 96 / 25.4
  const words = new Intl.Segmenter('th', { granularity: 'word' })
  const graphemes = new Intl.Segmenter('th', { granularity: 'grapheme' })
  const lines: string[] = []
  for (const paragraph of text.replace(/\t/g, '    ').split(/\r\n|\r|\n/)) {
    if (!paragraph) { lines.push(''); continue }
    let line = ''
    for (const { segment } of words.segment(paragraph)) {
      if (context.measureText(line + segment).width <= width) { line += segment; continue }
      if (line.trim()) lines.push(line.trimEnd())
      line = ''
      for (const { segment: glyph } of graphemes.segment(segment.trimStart())) {
        if (line && context.measureText(line + glyph).width > width) { lines.push(line); line = '' }
        line += glyph
      }
    }
    if (line) lines.push(line.trimEnd())
  }
  return lines
}

const requestLines = computed(() => wrapText(requestText.value))
const resultLines = computed(() => wrapText(props.ticket.resolution || ''))
function tableRows(lines: string[], count: number, reference: string): string[] {
  const content = lines.length > count ? [...lines.slice(0, count - 1), reference] : lines
  return Array.from({ length: count }, (_, i) => content[i] || '')
}
const requestRows = computed(() => tableRows(requestLines.value, 6, 'รายละเอียดการร้องขอต่อในเอกสารแนบ หน้า 2'))
const resultRows = computed(() => tableRows(resultLines.value, 4, 'ผลการดำเนินการต่อในเอกสารแนบ หน้า 2'))
type AnnexRow = { text: string; heading: boolean }
const annexes = computed(() => {
  const rows: AnnexRow[] = []
  const add = (title: string, lines: string[]) => {
    if (!lines.length) return
    rows.push({ text: title, heading: true }, ...lines.map(text => ({ text, heading: false })))
  }
  if (requestLines.value.length > 6) add('รายละเอียดการร้องขอ (ต่อ)', requestLines.value.slice(5))
  if (resultLines.value.length > 4) add('ผลการดำเนินการ (ต่อ)', resultLines.value.slice(3))
  if (props.ticket.approval?.decision_note) add('หมายเหตุหัวหน้าฝ่าย / ผู้อนุมัติ', wrapText(props.ticket.approval.decision_note))
  if (props.ticket.completion_approval_current && props.ticket.completion_approval?.decision_note) add('หมายเหตุ ผอ./ผจก. ผู้รับรองผล', wrapText(props.ticket.completion_approval.decision_note))
  if (props.assistantSignature?.note) add('หมายเหตุผู้ช่วยกรรมการผู้จัดการ', wrapText(props.assistantSignature.note))
  const pages: AnnexRow[][] = []
  for (let i = 0; i < rows.length; i += 34) pages.push(rows.slice(i, i + 34))
  return pages
})
const director = computed(() => props.ticket.completion_approval_current ? props.ticket.completion_approval : null)

async function prepare() {
  const sample = `ภาษาไทย ABC 123 ${props.ticket.requester_name} ${requestText.value} ${props.ticket.resolution || ''}`
  await Promise.all([document.fonts.load('9pt "Noto Serif Thai"', sample), document.fonts.load('700 10.5pt "Noto Serif Thai"', 'แบบฟอร์มร้องขอดำเนินการด้าน IT Request Form')])
  fontReady.value = true
  await nextTick()
  await Promise.all(Array.from(root.value?.querySelectorAll('img') || []).map(img => img.decode().catch(() => {})))
}
onMounted(prepare)
defineExpose({ prepare })
</script>

<template>
  <div ref="root" class="service-request-print">
    <article v-for="(annex, index) in [null, ...annexes]" :key="index" class="fm-page" :data-form-page="index + 1">
      <header class="form-header">
        <img :src="atsLogo" alt="Atthasan" class="ats-logo" />
        <h1>แบบฟอร์มร้องขอดำเนินการด้าน IT (IT Request Form)</h1>
        <p v-if="statusLabel" class="document-status">{{ statusLabel }}</p>
      </header>

      <table class="requester-table">
        <colgroup><col class="label-col" /><col /><col class="date-col" /></colgroup>
        <tbody>
          <tr><th>ชื่อผู้ร้องขอ :</th><td>{{ ticket.requester_name }}</td><td>วันที่ {{ fmtFormDate(ticket.created_at) || '........../........../..........' }}</td></tr>
          <tr><th>ตำแหน่ง :</th><td>{{ field('position') }}</td><td class="ticket-number">เลขที่ {{ ticket.ticket_no || '................................' }}</td></tr>
          <tr><th>ฝ่าย/แผนก :</th><td>{{ ticket.requester_dept }}</td><td /></tr>
          <tr v-if="index === 0"><td colspan="3" class="device-options"><span v-for="device in [{key:'computer',label:'Computer'},{key:'notebook',label:'Notebook'},{key:'other',label:'Other'}]" :key="device.key" class="printed-choice"><span :class="['checkbox', { checked: field('device_kind') === device.key }]" /> {{ device.label }}</span></td></tr>
        </tbody>
      </table>

      <template v-if="index === 0">
        <section class="action-section">
          <h2 class="section-tab">ต้องการให้ดำเนินการ</h2>
          <div class="action-box">
            <div class="action-line"><span :class="['checkbox', { checked: selected('replace_device') }]" /><span>เปลี่ยนอุปกรณ์</span></div>
            <div class="action-line"><span :class="['checkbox', { checked: selected('install') }]" /><span class="install-label">Format ติดตั้ง OS ใหม่ / ติดตั้งโปรแกรม / Update โปรแกรม / เปลี่ยน OS เป็น<span class="short-fill">{{ field('os_target') || '...............................' }}</span></span></div>
            <div class="action-line split-actions"><span class="printed-choice"><span :class="['checkbox', { checked: selected('remote_vpn') }]" /> ขอรีโมท/VPN</span><span class="printed-choice"><span :class="['checkbox', { checked: selected('company_email') }]" /> ขอ E-mail บริษัท</span></div>
            <div class="action-line"><span :class="['checkbox', { checked: selected('access') }]" /><span>ขอเพิ่มสิทธิ์/เปลี่ยนสิทธิ์การเข้าระบบ</span></div>
            <div class="action-line"><span :class="['checkbox', { checked: selected('other') }]" /><span>อื่นๆ</span><span class="other-fill">{{ field('other_text') || '\u00a0' }}</span></div>
          </div>
        </section>

        <section class="request-section">
          <h2 class="section-tab">รายละเอียดการร้องขอ</h2>
          <table class="lined-table"><colgroup><col class="number-col" /><col /></colgroup><thead><tr><th>ลำดับ</th><th>รายละเอียดการร้องขอ</th></tr></thead><tbody><tr v-for="(line, row) in requestRows" :key="row"><td class="row-number">{{ row === 0 && requestText ? '1' : '' }}</td><td>{{ line }}</td></tr></tbody></table>
        </section>

        <div class="request-signatures signature-pair">
          <PrintSignatory label="ผู้ร้องขอ" :name="ticket.requester_signature?.requester_name || ticket.requester_name" :signature="ticket.requester_signature?.signature_data" :signed-at="ticket.requester_signature?.signed_at" />
          <PrintSignatory label="หัวหน้าฝ่าย / ผู้อนุมัติ" :name="ticket.approval?.approver_name || field('approver_name')" :signature="ticket.approval?.signature_data" :signed-at="ticket.approval?.signed_at" :rejected="ticket.approval?.status === 'rejected'" />
        </div>

        <section class="result-section">
          <h2 class="section-tab">ผลการดำเนินการ</h2>
          <table class="lined-table"><colgroup><col class="number-col" /><col /></colgroup><thead><tr><th>ลำดับ</th><th>ผลการดำเนินการ</th></tr></thead><tbody><tr v-for="(line, row) in resultRows" :key="row"><td class="row-number">{{ row === 0 && ticket.resolution ? '1' : '' }}</td><td>{{ line }}</td></tr></tbody></table>
        </section>

        <div class="work-signatures signature-pair">
          <PrintSignatory label="เจ้าหน้าที่เทคโนโลยีสารสนเทศ" :name="ticket.it_signature?.signer_name || ticket.assigned_name" :signature="ticket.it_signature?.signature_data" :signed-at="ticket.it_signature?.signed_at" />
          <PrintSignatory label="ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง" :name="director?.approver_name || ticket.completion_approval?.approver_name" :signature="director?.signature_data" :signed-at="director?.signed_at" :rejected="director?.status === 'rejected'" />
        </div>
        <div class="managing-director"><PrintSignatory label="ผู้ช่วยกรรมการผู้จัดการ" :name="assistantSignature?.name" :signature="assistantSignature?.signature" :signed-at="assistantSignature?.signedAt" :rejected="assistantSignature?.rejected" /></div>
      </template>

      <section v-else class="annex-section">
        <h2 class="section-tab">เอกสารแนบ / รายละเอียดเพิ่มเติม</h2>
        <table class="lined-table"><colgroup><col class="number-col" /><col /></colgroup><thead><tr><th>บรรทัด</th><th>รายละเอียดต่อเนื่อง · {{ ticket.ticket_no }}</th></tr></thead><tbody><tr v-for="(row, number) in annex || []" :key="number"><td class="row-number">{{ number + 1 }}</td><td :class="{ 'annex-heading': row.heading }">{{ row.text }}</td></tr></tbody></table>
      </section>
      <footer class="form-footer"><span>FM-IT-010, Rev.02, 04/11/2569</span><span>{{ index === 0 && annexes.length ? 'มีรายละเอียดเพิ่มเติมในเอกสารแนบ · ' : '' }}หน้า {{ index + 1 }}/{{ annexes.length + 1 }}</span></footer>
    </article>
  </div>
</template>

<style scoped>
.service-request-print { display: none; color: #000; font-family: 'Noto Serif Thai', serif; font-size: 9pt; line-height: 1.3; }
.fm-page { width: 186.6mm; min-height: 260mm; margin: 0; }
.form-header { position: relative; height: 18mm; }
.ats-logo { display: block; width: 25mm; height: 18mm; object-fit: contain; }
.form-header h1 { position: absolute; right: 0; top: 6mm; left: 60mm; margin: 0; text-align: center; font-size: 10.5pt; font-weight: 700; white-space: nowrap; }
.document-status { position:absolute;right:0;top:13mm;font-size:8pt; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td { border: .2mm solid #000; padding: 0 1mm; font-weight: 400; text-align: left; vertical-align: middle; }
th { font-weight: 700; }
.requester-table .label-col { width: 19mm; }
.requester-table .date-col { width: 42mm; }
.requester-table tr { height: 5.75mm; }
.requester-table th { padding: 0 .5mm; font-size: 8pt; white-space: nowrap; }
.requester-table td { overflow-wrap: anywhere; }
.ticket-number { font-size: 8pt; }
.requester-table .device-options { text-align: center; font-weight: 700; }
.printed-choice { display: inline-flex; align-items: center; gap: 1.2mm; }
.device-options .printed-choice + .printed-choice { margin-left: 8mm; }
.checkbox { display: inline-block; position: relative; width: 2.2mm; height: 2.2mm; border: .25mm solid #000; flex-shrink: 0; vertical-align: -.2mm; }
.checkbox.checked::after { content: ''; position: absolute; left: .5mm; top: -.05mm; width: .8mm; height: 1.6mm; border: solid #000; border-width: 0 .35mm .35mm 0; transform: rotate(45deg); }
.section-tab { width: 57mm; min-height: 5.5mm; margin: 0; padding: .6mm .6mm .3mm; border: .2mm solid #000; border-bottom: 0; background: #9dc3e6; font-size: 9pt; font-weight: 700; }
.action-section { margin-top: 4mm; }
.action-box { border: .2mm solid #000; padding: 0 4mm; }
.action-line { display: flex; align-items: center; gap: 4mm; height: 5.75mm; }
.action-line .install-label { display: flex; align-items: baseline; flex: 1; min-width: 0; white-space: nowrap; font-size: 8.6pt; }
.short-fill { display: inline-block; flex: 1; min-width: 0; margin-left: 1mm; overflow: hidden; text-overflow: ellipsis; }
.split-actions { justify-content: flex-start; gap: 30mm; }
.other-fill { flex: 1; min-width: 0; border-bottom: .2mm dotted #000; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.request-section { margin-top: 5.5mm; }
.number-col { width: 10mm; }
.lined-table tr { height: 5.7mm; }
.lined-table th { height: 5.5mm; text-align: center; }
.lined-table td { white-space: pre; }
.row-number { text-align: center; font-size: 8pt; }
.signature-pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); justify-items: center; min-height: 24mm; break-inside: avoid; }
.request-signatures { padding: 0 16mm; }
.result-section { margin-top: 5mm; }
.work-signatures { margin-top: 5mm; }
.work-signatures :deep(.form-signatory) { position: relative; left: 8mm; width: 72mm; }
.managing-director { display: flex; justify-content: center; margin-top: 4mm; min-height: 24mm; break-inside: avoid; }
.managing-director :deep(.form-signatory) { position: relative; left: 8mm; }
.form-footer { display: flex; justify-content: space-between; align-items: baseline; gap: 4mm; margin-top: 6mm; font-size: 7.5pt; line-height: 4mm; break-inside: avoid; }
.annex-section { margin-top: 5.5mm; }
.annex-section .section-tab { width: 80mm; }
.annex-heading { font-weight: 700; }
@media print {
  .service-request-print { display: block; page: itServiceRequest; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .fm-page:not(:last-child) { break-after: page; }
}
@page itServiceRequest { size: A4 portrait; margin: 8mm 11mm 18mm 12.4mm; }
</style>
