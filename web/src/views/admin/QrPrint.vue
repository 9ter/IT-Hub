<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { api, type AssetRow } from '@/api'
import Icon from '@/components/Icon.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { assetUrl, qrDataUrl } from '@/qr'

const route = useRoute()
const labels = ref<(AssetRow & { qr: string })[]>([])
const loading = ref(true)
const printing = ref(false)
const error = ref('')
const preview = ref<HTMLElement | null>(null)
let mounted = true
/** roll = เครื่องพิมพ์สติกเกอร์ม้วน 50×40 mm (หน้าละ 1 ดวง), a4 = เรียงหลายดวงบนกระดาษ A4 */
const paper = ref<'roll' | 'a4'>('roll')

function beforePrint() {
  printing.value = true
}

function afterPrint() {
  if (!printing.value) return
  printing.value = false
  window.close()
}

async function print() {
  if (loading.value || printing.value || error.value || !labels.value.length) return
  printing.value = true
  try {
    await nextTick()
    await Promise.all([
      document.fonts.ready,
      ...Array.from(preview.value?.querySelectorAll('img') ?? []).map((img) => img.decode()),
    ])
    if (mounted) window.print()
  } catch {
    printing.value = false
    error.value = 'เตรียม QR สำหรับพิมพ์ไม่สำเร็จ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง'
  }
}

onMounted(async () => {
  window.addEventListener('beforeprint', beforePrint)
  window.addEventListener('afterprint', afterPrint)
  try {
    const query = Object.fromEntries(Object.entries(route.query).filter(([k, v]) => k !== 'ids' && k !== 'auto_print' && typeof v === 'string')) as Record<string, string>
    const { data } = await api.get<{ data: AssetRow[] }>('/admin/assets', { ...query, all: 1 })
    const ids = typeof route.query.ids === 'string' ? new Set(route.query.ids.split(',').map(Number)) : null
    const assets = ids ? data.filter((a) => ids.has(a.id)) : data
    const generated = await Promise.all(assets.map(async (a) => ({ ...a, qr: await qrDataUrl(assetUrl(a.asset_code)) })))
    if (!mounted) return
    labels.value = generated
  } catch (e) {
    if (mounted) error.value = e instanceof Error ? e.message : 'โหลดข้อมูลอุปกรณ์ไม่สำเร็จ กรุณาลองใหม่'
  } finally {
    if (mounted) loading.value = false
  }
  if (mounted && route.query.auto_print === '1') await print()
})

// @page เปลี่ยนตามชนิดกระดาษ และต้องถอดออกเมื่อออกจากหน้านี้
const pageStyle = document.createElement('style')
document.head.appendChild(pageStyle)
watchEffect(() => {
  pageStyle.textContent = paper.value === 'roll' ? '@page { size: 50mm 40mm; margin: 0; }' : '@page { size: A4; margin: 8mm; }'
})
onUnmounted(() => {
  mounted = false
  pageStyle.remove()
  window.removeEventListener('beforeprint', beforePrint)
  window.removeEventListener('afterprint', afterPrint)
})
</script>

<template>
  <div class="min-h-dvh bg-slate-100 dark:bg-slate-900 print:min-h-0 print:bg-white">
    <header class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3 no-print">
      <h1 class="font-bold">พิมพ์สติกเกอร์ QR 50×40 mm ({{ labels.length }} ดวง)</h1>
      <div class="ml-auto flex flex-wrap items-center gap-2 text-sm">
        <ThemeToggle />
        <span class="text-slate-500 dark:text-slate-400">กระดาษ</span>
        <div class="flex rounded-lg ring-1 ring-slate-300 dark:ring-slate-600">
          <button v-for="(label, k) in { roll: 'ม้วนสติกเกอร์', a4: 'A4' }" :key="k" :class="['px-3 py-1.5', paper === k ? 'bg-slate-900 text-white' : '']" @click="paper = k">
            {{ label }}
          </button>
        </div>
        <button class="btn-primary" :disabled="loading || printing || !!error || !labels.length" @click="print"><Icon name="printer" :size="16" /> พิมพ์</button>
      </div>
    </header>

    <p v-if="loading" class="p-10 text-center text-slate-500 dark:text-slate-400">กำลังสร้าง QR...</p>
    <p v-else-if="error" role="alert" class="p-10 text-center text-red-600 dark:text-red-400">{{ error }}</p>
    <p v-else-if="!labels.length" class="p-10 text-center text-slate-500 dark:text-slate-400">ไม่พบอุปกรณ์สำหรับพิมพ์ QR</p>
    <div v-else ref="preview" :class="['mx-auto flex max-w-[210mm] flex-wrap gap-[3mm] bg-white p-[8mm] print:p-0', paper === 'roll' && 'print:block']">
      <div
        v-for="a in labels"
        :key="a.id"
        :class="[
          'flex h-[40mm] w-[50mm] shrink-0 break-inside-avoid items-center gap-[1.5mm] overflow-hidden rounded-lg border border-dashed border-slate-400 p-[2mm] text-black',
          paper === 'roll' && 'print:rounded-none print:border-0 print:not-last:break-after-page',
        ]"
      >
        <img :src="a.qr" :alt="a.asset_code" class="aspect-square h-[29mm] w-[29mm] shrink-0" />
        <!-- ข้อความหมุน 90° อ่านจากล่างขึ้นบน: หมุนสติกเกอร์ตามเข็มนาฬิกาแล้ว QR อยู่บน ข้อความอยู่ล่าง -->
        <div class="flex h-full min-w-0 flex-1 rotate-180 flex-col items-center justify-center gap-[1mm] text-center [writing-mode:vertical-rl]">
          <p class="text-[6.5pt] leading-tight font-semibold tracking-wide">IT HUB · สแกนเพื่อแจ้งซ่อม</p>
          <p class="font-mono text-[10pt] leading-tight font-bold break-all">{{ a.asset_code }}</p>
          <p v-if="a.brand_model" class="max-h-full truncate text-[6pt] leading-tight text-slate-600">{{ a.brand_model }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
