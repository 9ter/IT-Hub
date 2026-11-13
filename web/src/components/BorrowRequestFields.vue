<script setup lang="ts">
import type { BorrowRequestData } from '@/api'
import { BORROW_ITEMS_MAX,BORROW_QUANTITY_MAX } from '@/requestEdits'
defineProps<{disabled?:boolean;errors?:Record<string,string>}>()
const model=defineModel<BorrowRequestData>({required:true})
function add(){if(model.value.borrow_items.length<BORROW_ITEMS_MAX)model.value.borrow_items.push({device_kind:'',item:'',quantity:1})}
function remove(i:number){if(model.value.borrow_items.length>1)model.value.borrow_items.splice(i,1)}
</script>
<template>
  <fieldset :disabled="disabled" class="min-w-0 space-y-4">
    <div><label for="edit_borrow_description" class="label">วัตถุประสงค์การใช้งาน <span class="text-red-500">*</span></label><textarea id="edit_borrow_description" v-model="model.description" class="input" rows="3" maxlength="5000" required /><p v-if="errors?.description" class="field-error">{{errors.description}}</p></div>
    <div class="space-y-3">
      <div class="flex flex-wrap items-center gap-3"><span class="label mb-0 flex-1">รายการอุปกรณ์ ({{model.borrow_items.length}})</span><button type="button" class="btn-secondary" :disabled="model.borrow_items.length>=BORROW_ITEMS_MAX" @click="add">+ เพิ่มรายการ</button></div>
      <p v-if="errors?.borrow_items" class="field-error">{{errors.borrow_items}}</p>
      <article v-for="(row,i) in model.borrow_items" :key="i" class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
        <div class="mb-2 flex items-center justify-between gap-3"><h4 class="text-sm font-semibold">รายการที่ {{i+1}}</h4><button v-if="model.borrow_items.length>1" type="button" class="text-sm text-red-600 dark:text-red-400" :aria-label="`ลบอุปกรณ์รายการที่ ${i+1}`" @click="remove(i)">ลบรายการ</button></div>
        <p v-if="errors?.[`borrow_items.${i}`]" class="field-error">{{errors[`borrow_items.${i}`]}}</p>
        <div class="grid min-w-0 gap-3 sm:grid-cols-[9rem_minmax(0,1fr)_6rem]">
          <div><label class="label" :for="`edit-borrow-kind-${i}`">ประเภท <span class="text-red-500">*</span></label><select :id="`edit-borrow-kind-${i}`" v-model="row.device_kind" class="input"><option value="">เลือกประเภท</option><option value="notebook">Notebook</option><option value="other">อื่นๆ</option></select><p v-if="errors?.[`borrow_items.${i}.device_kind`]" class="field-error">{{errors[`borrow_items.${i}.device_kind`]}}</p></div>
          <div class="min-w-0"><label class="label" :for="`edit-borrow-item-${i}`">อุปกรณ์ที่ต้องการ <span class="text-red-500">*</span></label><input :id="`edit-borrow-item-${i}`" v-model="row.item" class="input" maxlength="150" /><p v-if="errors?.[`borrow_items.${i}.item`]" class="field-error">{{errors[`borrow_items.${i}.item`]}}</p></div>
          <div><label class="label" :for="`edit-borrow-qty-${i}`">จำนวน <span class="text-red-500">*</span></label><input :id="`edit-borrow-qty-${i}`" v-model.number="row.quantity" class="input" type="number" min="1" :max="BORROW_QUANTITY_MAX" /><p v-if="errors?.[`borrow_items.${i}.quantity`]" class="field-error">{{errors[`borrow_items.${i}.quantity`]}}</p></div>
        </div>
      </article>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="edit_borrow_start" class="label">วันที่เริ่มใช้ <span class="text-red-500">*</span></label><input id="edit_borrow_start" v-model="model.start_date" class="input" type="date" required /><p v-if="errors?.start_date" class="field-error">{{errors.start_date}}</p></div>
      <div><label for="edit_borrow_end" class="label">วันที่คืน</label><input id="edit_borrow_end" v-model="model.end_date" class="input" type="date" /><p v-if="errors?.end_date" class="field-error">{{errors.end_date}}</p></div>
    </div>
  </fieldset>
</template>
