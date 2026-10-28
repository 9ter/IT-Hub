<script setup lang="ts">
import { onMounted, onUnmounted, ref, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { api, type AssetRow } from '@/api'
import Icon from '@/components/Icon.vue'
import { assetUrl, qrDataUrl } from '@/qr'

const route = useRoute()
const labels = ref<(AssetRow & { qr: string })[]>([])
const loading = ref(true)
/** roll = เครื่องพิมพ์สติกเกอร์ม้วน 50×40 mm (หน้าละ 1 ดวง), a4 = เรียงหลายดวงบนกระดาษ A4 */
const paper = ref<'roll' | 'a4'>('roll')

onMounted(async () => {
  const query = Object.fromEntries(Object.entries(route.query).filter(([k, v]) => k !== 'ids' && typeof v === 'string')) as Record<string, string>
  const { data } = await api.get<{ data: AssetRow[] }>('/admin/assets', { ...query, all: 1 })
  const ids = typeof route.query.ids === 'string' ? new Set(route.query.ids.split(',').map(Number)) : null
  const assets = ids ? data.filter((a) => ids.has(a.id)) : data
  labels.value = await Promise.all(assets.map(async (a) => ({ ...a, qr: await qrDataUrl(assetUrl(a.asset_code)) })))
  loading.value = false
})

// @page เปลี่ยนตามชนิดกระดาษ และต้องถอดออกเมื่อออกจากหน้านี้
const pageStyle = document.createElement('style')
document.head.appendChild(pageStyle)
watchEffect(() => {
  pageStyle.textContent = paper.value === 'roll' ? '@page { size: 50mm 40mm; margin: 0; }' : '@page { size: A4; margin: 8mm; }'
})
onUnmounted(() => pageStyle.remove())

const print = () => window.print()
</script>

<template>
  <div class="min-h-dvh bg-slate-100 print:min-h-0 print:bg-white">
    <header class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 no-print">
      <h1 class="font-bold">พิมพ์สติกเกอร์ QR 50×40 mm ({{ labels.length }} ดวง)</h1>
      <div class="ml-auto flex items-center gap-2 text-sm">
        <span class="text-slate-500">กระดาษ</span>
        <div class="flex rounded-lg ring-1 ring-slate-300">
          <button v-for="(label, k) in { roll: 'ม้วนสติกเกอร์', a4: 'A4' }" :key="k" :class="['px-3 py-1.5', paper === k ? 'bg-slate-900 text-white' : '']" @click="paper = k">
            {{ label }}
          </button>
        </div>
        <button class="btn-primary" :disabled="loading" @click="print"><Icon name="printer" :size="16" /> พิมพ์</button>
      </div>
    </header>

    <p v-if="loading" class="p-10 text-center text-slate-500">กำลังสร้าง QR...</p>
    <div v-else :class="['mx-auto flex max-w-[210mm] flex-wrap gap-[3mm] bg-white p-[8mm] print:p-0', paper === 'roll' && 'print:block']">
      <div
        v-for="a in labels"
        :key="a.id"
        :class="[
          'flex h-[40mm] w-[50mm] shrink-0 break-inside-avoid flex-col items-center justify-between overflow-hidden rounded-lg border border-dashed border-slate-400 p-[1.5mm] text-center text-black',
          paper === 'roll' && 'print:rounded-none print:border-0 print:not-last:break-after-page',
        ]"
      >
        <p class="text-[7pt] leading-tight font-semibold tracking-wide">IT HUB · สแกนเพื่อแจ้งซ่อม</p>
        <img :src="a.qr" :alt="a.asset_code" class="aspect-square h-[26mm] w-[26mm]" />
        <div class="w-full">
          <p class="font-mono text-[10pt] leading-tight font-bold">{{ a.asset_code }}</p>
          <p v-if="a.brand_model" class="truncate text-[6pt] leading-tight text-slate-600">{{ a.brand_model }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
