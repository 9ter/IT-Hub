<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, type AssetRow } from '@/api'
import Icon from '@/components/Icon.vue'
import { assetUrl, qrDataUrl } from '@/qr'

const route = useRoute()
const labels = ref<(AssetRow & { qr: string })[]>([])
const loading = ref(true)
const size = ref<'s' | 'm' | 'l'>('m')

onMounted(async () => {
  const query = Object.fromEntries(Object.entries(route.query).filter(([k, v]) => k !== 'ids' && typeof v === 'string')) as Record<string, string>
  const { data } = await api.get<{ data: AssetRow[] }>('/admin/assets', { ...query, all: 1 })
  const ids = typeof route.query.ids === 'string' ? new Set(route.query.ids.split(',').map(Number)) : null
  const assets = ids ? data.filter((a) => ids.has(a.id)) : data
  labels.value = await Promise.all(assets.map(async (a) => ({ ...a, qr: await qrDataUrl(assetUrl(a.asset_code)) })))
  loading.value = false
})

const grid = { s: 'grid-cols-5', m: 'grid-cols-4', l: 'grid-cols-3' }
const print = () => window.print()
</script>

<template>
  <div class="min-h-dvh bg-slate-100 print:bg-white">
    <header class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 no-print">
      <h1 class="font-bold">พิมพ์สติกเกอร์ QR ({{ labels.length }} ดวง)</h1>
      <div class="ml-auto flex items-center gap-2 text-sm">
        <span class="text-slate-500">ขนาด</span>
        <div class="flex rounded-lg ring-1 ring-slate-300">
          <button v-for="(label, k) in { s: 'เล็ก', m: 'กลาง', l: 'ใหญ่' }" :key="k" :class="['px-3 py-1.5', size === k ? 'bg-slate-900 text-white' : '']" @click="size = k">
            {{ label }}
          </button>
        </div>
        <button class="btn-primary" :disabled="loading" @click="print"><Icon name="printer" :size="16" /> พิมพ์</button>
      </div>
    </header>

    <p v-if="loading" class="p-10 text-center text-slate-500">กำลังสร้าง QR...</p>
    <div v-else :class="['mx-auto grid max-w-[210mm] gap-[3mm] bg-white p-[8mm] print:p-0', grid[size]]">
      <div v-for="a in labels" :key="a.id" class="flex break-inside-avoid flex-col items-center rounded-lg border border-dashed border-slate-400 p-[2.5mm] text-center text-black">
        <p class="text-[8pt] font-semibold tracking-wide">IT HUB · สแกนเพื่อแจ้งซ่อม</p>
        <img :src="a.qr" :alt="a.asset_code" class="my-[1mm] aspect-square w-full" />
        <p :class="['font-mono leading-tight font-bold', size === 's' ? 'text-[9pt]' : 'text-[12pt]']">{{ a.asset_code }}</p>
        <p v-if="size !== 's'" class="line-clamp-1 text-[7pt] text-slate-600">{{ a.brand_model }}</p>
      </div>
    </div>
  </div>
</template>

<style>
@page {
  size: A4;
  margin: 8mm;
}
</style>
