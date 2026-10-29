<script setup lang="ts">
import type { DocumentDefinition,ITDocument } from '@/documents'
import PrintSignatory from './PrintSignatory.vue'
const props=defineProps<{document:ITDocument;definition:DocumentDefinition;keys:string[]}>()
const entry=(key:string)=>props.document.signatures.find(s=>s.role_key===key)
</script>
<template>
  <div v-if="keys.length" class="doc-signature-group" :style="{gridTemplateColumns:`repeat(${Math.min(keys.length,3)},minmax(0,1fr))`}">
    <PrintSignatory v-for="key in keys" :key="key" :label="definition.roles.find(r=>r.key===key)?.label||key" :name="entry(key)?.signer_name" :signature="entry(key)?.signature_data" :signed-at="entry(key)?.signed_at" :rejected="entry(key)?.status==='rejected'" />
  </div>
</template>
<style scoped>
.doc-signature-group{display:grid;gap:5mm 7mm;justify-items:center;margin:6mm 0;break-inside:avoid}
.doc-signature-group :deep(.signer-role){font-size:8pt}
.doc-signature-group :deep(.signature-line){width:48mm}
</style>
