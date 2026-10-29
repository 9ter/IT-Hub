<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentField, DocumentValue } from '@/documents'
const props=defineProps<{field:DocumentField;modelValue:DocumentValue|undefined;disabled?:boolean;id?:string}>()
const emit=defineEmits<{ 'update:modelValue':[value:DocumentValue] }>()
const value=computed({get:()=>Array.isArray(props.modelValue)?'':(props.modelValue||''),set:v=>emit('update:modelValue',v)})
const selected=computed(()=>Array.isArray(props.modelValue)?props.modelValue:[])
function toggle(key:string,checked:boolean){emit('update:modelValue',checked?[...selected.value,key]:selected.value.filter(k=>k!==key))}
</script>
<template>
  <div v-if="field.type==='multiselect'" class="grid gap-2 sm:grid-cols-2">
    <label v-for="(label,key) in field.options" :key="key" class="choice"><input type="checkbox" :disabled="disabled" :checked="selected.includes(String(key))" @change="toggle(String(key),($event.target as HTMLInputElement).checked)" />{{label}}</label>
  </div>
  <select v-else-if="field.type==='select'" :id="id" v-model="value" :disabled="disabled" class="input"><option value="">— เลือก —</option><option v-for="(label,key) in field.options" :key="key" :value="key">{{label}}</option></select>
  <textarea v-else-if="field.type==='textarea'" :id="id" v-model="value" :disabled="disabled" class="input" rows="3" maxlength="12000" />
  <input v-else :id="id" v-model="value" :disabled="disabled" class="input" :type="field.type==='decimal'?'number':field.type" :step="field.type==='decimal'?'0.01':undefined" :min="['number','decimal'].includes(field.type)?0:undefined" :maxlength="1000" />
</template>
