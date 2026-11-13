<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { fmtFormDate } from '@/format'
import { inspectionItemName, inspectionMarks, inspectionMonths, type InspectionYear } from '@/inspections'
import atsLogo from '@/assets/ats-logo.png'
import '@fontsource/noto-serif-thai/400.css'
import '@fontsource/noto-serif-thai/700.css'

/** FM-IT-002 printed like the original sheet: 12 month columns and date / inspector / reviewer footer rows. */
const props = defineProps<{ data: InspectionYear }>()
const root = ref<HTMLElement | null>(null)
const footer = [
  { key: 'inspected_on', label: 'วันที่ตรวจ' },
  { key: 'inspector', label: 'ผู้ตรวจสอบ' },
  { key: 'reviewer', label: 'ผู้ทบทวน' },
] as const
function footerValue(month: number, key: typeof footer[number]['key']) {
  const m = props.data.months[month]
  if (!m) return ''
  return key === 'inspected_on' ? fmtFormDate(m.inspected_on) : m[key] || ''
}
async function prepare() {
  await Promise.all([document.fonts.load('8pt "Noto Serif Thai"', 'ใบตรวจสอบโครงสร้างพื้นฐาน ABC 0123 / X ✓'), document.fonts.load('700 12pt "Noto Serif Thai"', 'ใบตรวจสอบโครงสร้างพื้นฐาน')])
  await nextTick()
  await Promise.all(Array.from(root.value?.querySelectorAll('img') || []).map(img => img.decode().catch(() => {})))
}
defineExpose({ prepare })
</script>

<template>
  <div ref="root" class="inspection-print">
    <article class="sheet">
      <header><img :src="atsLogo" alt="Atthasan" /><div><h1>{{ data.form.title }}</h1><p>ผลตรวจสอบประจำปี {{ data.year }}</p></div><p class="legend">( / = ปกติ, X = ผิดปกติ, ✓ = แก้ไขแล้ว )</p></header>
      <table>
        <colgroup><col style="width:11mm" /><col style="width:33mm" /><col style="width:26mm" /><col v-for="m in 12" :key="m" /><col style="width:38mm" /></colgroup>
        <thead>
          <tr><th rowspan="2">ลำดับ</th><th rowspan="2">รายการตรวจสอบ</th><th rowspan="2">หมายเลขเครื่อง</th><th colspan="12">ผลตรวจสอบประจำปี : {{ data.year }}</th><th rowspan="2">หมายเหตุ</th></tr>
          <tr><th v-for="m in inspectionMonths" :key="m">{{ m }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="(item, i) in data.items" :key="item.id">
            <td class="center">{{ i + 1 }}</td><td>{{ inspectionItemName(item) }}</td><td>{{ item.asset_code || '' }}</td>
            <td v-for="m in 12" :key="m" class="center mark">{{ item.results[m] ? inspectionMarks[item.results[m]!] : '' }}</td>
            <td>{{ item.notes || '' }}</td>
          </tr>
        </tbody>
        <tbody class="meta-group">
          <tr v-for="(f, i) in footer" :key="f.key" :class="['meta', { first: i === 0 }]">
            <th colspan="3" class="meta-label">{{ f.label }}</th>
            <td v-for="m in 12" :key="m" class="center small">{{ footerValue(m, f.key) }}</td>
            <td />
          </tr>
        </tbody>
      </table>
      <footer><span>{{ data.form.code }}, {{ data.form.rev }}</span><span>บริษัท อัตถสาร จำกัด</span></footer>
    </article>
  </div>
</template>

<style scoped>
.inspection-print{display:none;color:#000;font-family:'Noto Serif Thai',serif;font-size:8pt;line-height:1.4}
.sheet{width:276mm;background:#fff}
header{display:flex;align-items:center;gap:4mm;margin-bottom:2mm}header img{width:22mm;height:16mm;object-fit:contain}header>div{flex:1;text-align:center}header h1{font-size:12pt;font-weight:700;margin:0}header p{margin:.5mm 0;font-size:8.5pt}.legend{font-size:7.5pt;white-space:nowrap}
table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.5pt}
th,td{border:.2mm solid #000;padding:.6mm 1mm;vertical-align:middle;overflow-wrap:anywhere}
th{font-weight:700;text-align:center;background:#f1f5f9}
thead{display:table-header-group}tr,.meta-group{break-inside:avoid}
.center{text-align:center}.mark{font-size:9pt;padding:0}.small{font-size:6.5pt;padding:.4mm .2mm}
.meta .meta-label{text-align:left;background:#fff}.meta.first td,.meta.first th{border-top:.5mm solid #000}
footer{display:flex;justify-content:space-between;border-top:.2mm solid #000;margin-top:3mm;padding-top:1.5mm;font-size:7pt;break-inside:avoid}
@page inspectionLandscape{size:A4 landscape;margin:9mm 10.5mm 11mm}
@media print{
  .inspection-print{display:block}
  .sheet{page:inspectionLandscape;print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
