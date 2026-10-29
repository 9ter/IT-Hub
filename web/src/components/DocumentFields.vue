<script setup lang="ts">
import { computed } from 'vue'
import { displayDocumentValue,type DocumentDefinition,type ITDocument } from '@/documents'
const props=defineProps<{document:ITDocument;definition:DocumentDefinition;keys:string[];columns?:number}>()
const fields=computed(()=>props.keys.map(k=>props.definition.fields.find(f=>f.key===k)).filter(f=>!!f))
</script>
<template>
  <div class="doc-fields" :style="{gridTemplateColumns:`repeat(${columns||1},minmax(0,1fr))`}">
    <div v-for="f in fields" :key="f.key" :class="['doc-field',{'wide-field':['textarea','multiselect'].includes(f.type)}]">
      <b>{{f.label}} :</b>
      <span v-if="f.type==='select'||f.type==='multiselect'" class="doc-options"><span v-for="(label,k) in f.options" :key="k"><span class="printed-box">{{Array.isArray(document.data.fields[f.key])?(document.data.fields[f.key] as string[]).includes(String(k))?'✓':'':document.data.fields[f.key]===String(k)?'✓':''}}</span> {{label}}</span></span>
      <span v-else :class="{'paragraph-field':f.type==='textarea','blank-fill':!displayDocumentValue(f,document.data.fields[f.key])}">{{displayDocumentValue(f,document.data.fields[f.key])||'\u00a0'}}</span>
    </div>
  </div>
</template>
<style scoped>
.doc-fields{display:grid;border-top:.2mm solid #000;border-left:.2mm solid #000;margin:2mm 0}
.doc-field{border-right:.2mm solid #000;border-bottom:.2mm solid #000;min-height:6mm;padding:1mm 2mm;overflow-wrap:anywhere;white-space:pre-wrap}
.doc-field b{margin-right:2mm}.wide-field{grid-column:1/-1}.paragraph-field{display:block;min-height:12mm;margin-top:1mm}
.blank-fill{display:inline-block;min-width:12mm;width:40%;max-width:100%;border-bottom:.2mm dotted #555}.blank-fill.paragraph-field{display:block;width:auto;border-bottom:0}
.doc-options{display:inline-flex;gap:1mm 4mm;flex-wrap:wrap}.doc-options>span{display:inline-block}
.printed-box{display:inline-flex;align-items:center;justify-content:center;border:.2mm solid #000;width:2.7mm;height:2.7mm;font:8pt sans-serif;vertical-align:middle}
</style>
