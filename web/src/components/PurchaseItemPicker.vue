<script setup lang="ts">
import { computed } from 'vue'
import { PURCHASE_ITEM_DETAIL_MAX, PURCHASE_ITEM_OTHER_MAX, PURCHASE_QUANTITY_MAX } from '@/requestEdits'
import { meta } from '@/store'
const props=defineProps<{options:Record<string,string>;errors?:Record<string,string>;idPrefix:string}>()
const items=defineModel<string[]>('items',{required:true})
const quantities=defineModel<Record<string,number|''>>('quantities',{required:true})
const details=defineModel<Record<string,string>>('details',{required:true})
/** Equipment type typed by the requester for the "อื่นๆ" item. */
const other=defineModel<string>('other',{default:''})
/** A newly ticked item starts at 1; an item from an older request without a quantity stays empty until filled. */
function toggle(key:string,checked:boolean){
  if(checked){if(!items.value.includes(key))items.value=[...items.value,key];if(!(key in quantities.value))quantities.value={...quantities.value,[key]:1}}
  else{items.value=items.value.filter(k=>k!==key);const next={...quantities.value};delete next[key];quantities.value=next;if(key in details.value){const rest={...details.value};delete rest[key];details.value=rest}}
}
function setDetail(key:string,value:string){details.value={...details.value,[key]:value}}
function setQuantity(key:string,value:string){quantities.value={...quantities.value,[key]:value===''?'':Number(value)}}
/** Retired items appear only on a request that already has them, so it can be read and changed. */
const visible=computed(()=>Object.entries(props.options).filter(([key])=>!meta.value?.purchase_items_retired?.includes(key)||items.value.includes(key)))
const qtyError=(key:string)=>props.errors?.[`item_quantities.${key}`]
const detailError=(key:string)=>props.errors?.[`item_details.${key}`]
</script>
<template>
  <div class="grid gap-2 sm:grid-cols-2">
    <div v-for="[key,label] in visible" :key="key" :class="['flex flex-col gap-2 rounded-xl border bg-white px-3.5 py-3 text-[15px] transition hover:border-brand-500 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 dark:bg-slate-900 dark:hover:border-brand-500 dark:has-[:checked]:border-brand-500 dark:has-[:checked]:bg-brand-900/50',qtyError(key)||detailError(key)||(key==='other'&&errors?.item_other)?'border-red-400 dark:border-red-500':'border-slate-200 dark:border-slate-600']">
      <label class="flex cursor-pointer items-start gap-3"><input type="checkbox" class="mt-1 size-4 shrink-0 accent-brand-700" :checked="items.includes(key)" @change="toggle(key,($event.target as HTMLInputElement).checked)" /><span class="min-w-0">{{label}}<span v-if="key==='other'" class="text-slate-500 dark:text-slate-400"> (โปรดระบุ)</span></span></label>
      <div v-if="key==='other'&&items.includes(key)" class="pl-7">
        <label :for="`${idPrefix}-other`" class="mb-1 block text-sm text-slate-600 dark:text-slate-300">ประเภทอุปกรณ์ที่ต้องการ <span class="text-red-500 dark:text-red-400">*</span></label>
        <input :id="`${idPrefix}-other`" v-model="other" type="text" required :maxlength="PURCHASE_ITEM_OTHER_MAX" :class="['input py-1.5',errors?.item_other&&'input-error']" placeholder="เช่น เครื่องฉายภาพ (Projector)" :aria-invalid="errors?.item_other?'true':undefined" :aria-describedby="errors?.item_other?`${idPrefix}-other-error`:undefined" />
        <p v-if="errors?.item_other" :id="`${idPrefix}-other-error`" class="field-error">{{errors.item_other}}</p>
      </div>
      <div v-if="items.includes(key)" class="flex items-center gap-2 pl-7">
        <label :for="`${idPrefix}-qty-${key}`" class="text-sm text-slate-600 dark:text-slate-300">จำนวน <span class="text-red-500 dark:text-red-400">*</span></label>
        <input :id="`${idPrefix}-qty-${key}`" type="number" inputmode="numeric" min="1" :max="PURCHASE_QUANTITY_MAX" step="1" required :value="quantities[key]??''" :class="['input w-24 py-1.5',qtyError(key)&&'input-error']" :aria-invalid="qtyError(key)?'true':undefined" :aria-describedby="qtyError(key)?`${idPrefix}-qty-${key}-error`:undefined" @input="setQuantity(key,($event.target as HTMLInputElement).value)" />
      </div>
      <p v-if="qtyError(key)" :id="`${idPrefix}-qty-${key}-error`" class="field-error pl-7">{{qtyError(key)}}</p>
      <div v-if="items.includes(key)" class="pl-7">
        <label :for="`${idPrefix}-detail-${key}`" class="mb-1 block text-sm text-slate-600 dark:text-slate-300">รายละเอียด เช่น ยี่ห้อ รุ่น สี (ถ้ามี)</label>
        <input :id="`${idPrefix}-detail-${key}`" type="text" :maxlength="PURCHASE_ITEM_DETAIL_MAX" :value="details[key]??''" :class="['input py-1.5',detailError(key)&&'input-error']" :placeholder="key==='ink'?'เช่น HP 682 สีดำ 1 สีฟ้า 1':'เช่น ยี่ห้อ / รุ่น / สเปก'" :aria-invalid="detailError(key)?'true':undefined" :aria-describedby="detailError(key)?`${idPrefix}-detail-${key}-error`:undefined" @input="setDetail(key,($event.target as HTMLInputElement).value)" />
        <p v-if="detailError(key)" :id="`${idPrefix}-detail-${key}-error`" class="field-error">{{detailError(key)}}</p>
      </div>
    </div>
  </div>
</template>
