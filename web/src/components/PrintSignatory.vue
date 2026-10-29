<script setup lang="ts">
import { fmtFormDate } from '@/format'

defineProps<{ label: string; name?: string | null; signature?: string | null; signedAt?: string | null; rejected?: boolean }>()
</script>

<template>
  <div class="form-signatory">
    <div class="signature-line"><img v-if="signature" :src="signature" :alt="`ลายเซ็น${label}`" /></div>
    <p class="signer-name">{{ name ? `(${name})` : '\u00a0' }}</p>
    <p class="signer-date">{{ signedAt ? fmtFormDate(signedAt) : '............./............../..............' }}<span v-if="rejected"> · ไม่อนุมัติ</span></p>
    <p class="signer-role">{{ label }}</p>
  </div>
</template>

<style scoped>
.form-signatory { width: 68mm; max-width: 100%; text-align: center; break-inside: avoid; }
.signature-line { display: flex; align-items: flex-end; justify-content: center; width: 52mm; max-width: 100%; height: 10mm; margin: 0 auto; border-bottom: .2mm dotted #000; }
.signature-line img { display: block; max-width: 50mm; max-height: 9.5mm; object-fit: contain; }
.signer-name { min-height: 3.5mm; font-size: 8pt; line-height: 3.5mm; overflow-wrap: anywhere; }
.signer-date { min-height: 3.8mm; font-size: 8pt; line-height: 3.8mm; }
.signer-role { min-height: 4.2mm; font-size: 8.8pt; line-height: 4.2mm; }
</style>
