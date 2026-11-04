<script setup lang="ts">
import type { PurchaseRequestData } from '@/api'
import { meta } from '@/store'
defineProps<{disabled?:boolean;errors?:Record<string,string>}>()
const model=defineModel<PurchaseRequestData>({required:true})
</script>
<template>
  <fieldset :disabled="disabled" class="min-w-0 space-y-4">
    <div><label for="edit_description" class="label">รายละเอียด / วัตถุประสงค์ <span class="text-red-500">*</span></label><textarea id="edit_description" v-model="model.description" class="input" rows="5" maxlength="5000" required /><p v-if="errors?.description" class="field-error">{{errors.description}}</p></div>
    <div><span class="label">ประเภทอุปกรณ์ที่ต้องการ <span class="text-red-500">*</span></span><div class="grid gap-2 sm:grid-cols-2"><label v-for="(label,key) in meta?.purchase_items" :key="key" class="choice"><input v-model="model.items" type="checkbox" :value="key" />{{label}}</label></div><p v-if="errors?.items" class="field-error">{{errors.items}}</p></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="edit_position" class="label">ตำแหน่ง</label><input id="edit_position" v-model="model.position" class="input" maxlength="100" /></div>
      <div><label for="edit_need_by" class="label">ต้องการภายในวันที่</label><input id="edit_need_by" v-model="model.need_by" class="input" type="date" /><p v-if="errors?.need_by" class="field-error">{{errors.need_by}}</p></div>
      <div><label for="edit_cpu" class="label">CPU</label><input id="edit_cpu" v-model="model.spec_cpu" class="input" maxlength="100" /></div>
      <div><label for="edit_ram" class="label">RAM</label><input id="edit_ram" v-model="model.spec_ram" class="input" maxlength="50" /></div>
      <div><label for="edit_storage" class="label">SSD / HDD</label><input id="edit_storage" v-model="model.spec_storage" class="input" maxlength="50" /></div>
      <div><label for="edit_display" class="label">ขนาดจอ</label><input id="edit_display" v-model="model.spec_display" class="input" maxlength="50" /></div>
    </div>
    <div><label for="edit_other" class="label">สเปกอื่นๆ</label><textarea id="edit_other" v-model="model.spec_other" class="input" rows="2" maxlength="255" /></div>
    <label class="flex items-center gap-2 text-sm"><input v-model="model.urgent" type="checkbox" />ต้องการด่วน</label>
  </fieldset>
</template>
