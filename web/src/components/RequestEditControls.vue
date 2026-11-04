<script setup lang="ts">
import { computed,ref } from 'vue'
import { api,ApiError,type Ticket } from '@/api'
import { fmtDateTime } from '@/format'
import { toast } from '@/store'
import { purchaseRequestData,requestEditChanges } from '@/requestEdits'
import PurchaseRequestFields from './PurchaseRequestFields.vue'
const props=defineProps<{ticket:Ticket;busy:boolean}>()
const emit=defineEmits<{updated:[ticket:Ticket,preserveDraft:boolean];busy:[busy:boolean]}>()
const mode=ref<'edit'|'return'|''>(''),saving=ref(false),error=ref(''),errors=ref<Record<string,string>>({}),reason=ref(''),version=ref('')
const data=ref(purchaseRequestData({}))
const blocked=computed(()=>props.busy||saving.value||['rejected','cancelled'].includes(props.ticket.status))
const returned=computed(()=>props.ticket.request_edit_return)
function open(next:'edit'|'return'){
  data.value=purchaseRequestData({...props.ticket.details,description:props.ticket.description})
  version.value=props.ticket.request_edit_version||'';reason.value='';error.value='';errors.value={};mode.value=next
}
async function submit(){
  if(blocked.value)return
  if(mode.value==='edit'&&!data.value.items.length){errors.value={items:'กรุณาเลือกประเภทอุปกรณ์อย่างน้อย 1 รายการ'};return}
  const id=props.ticket.id,kind=mode.value
  saving.value=true;emit('busy',true);error.value='';errors.value={}
  try{
    const updated=await api.post<Ticket>(`/admin/tickets/${id}/${kind==='edit'?'request-edit':'return-to-requester'}`,{version:version.value,reason:reason.value,...(kind==='edit'?{request:data.value}:{})})
    if(props.ticket.id!==id)return
    emit('updated',updated,true);mode.value=''
    if(kind==='edit')toast('แก้ไขรายละเอียดแล้ว โดยคงลายเซ็นเดิม')
    else toast(updated.request_edit_return?.mail_status==='sent'?'ส่งอีเมลให้ผู้ขอแก้ไขรายละเอียดแล้ว':updated.request_edit_return?.mail_error||'ส่งอีเมลไม่สำเร็จ',updated.request_edit_return?.mail_status==='sent'?'ok':'error')
  }catch(e){error.value=(e as Error).message;if(e instanceof ApiError)errors.value=e.errors}
  finally{saving.value=false;emit('busy',false)}
}
</script>
<template>
  <div class="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700">
    <p class="text-sm text-slate-500 dark:text-slate-400">แก้ไขรายละเอียดคำขอได้โดยคงลายเซ็นเดิม ระบบเก็บประวัติก่อนและหลังแก้ไข</p>
    <div v-if="returned" class="rounded-lg bg-blue-50 p-3 text-sm dark:bg-blue-950/50">
      <p class="font-medium">{{returned.status==='submitted'?'ผู้ขอแก้ไขและส่งกลับแล้ว':returned.status==='pending'?'ส่งกลับรอผู้ขอแก้ไข':'คำขอแก้ไขถูกแทนที่แล้ว'}}</p>
      <p class="mt-1 whitespace-pre-wrap break-words">เหตุผล: {{returned.reason}}</p>
      <p class="mt-1 break-all text-xs">ผู้รับ: {{returned.requester_email}} · {{fmtDateTime(returned.submitted_at||returned.created_at)}}</p>
      <p v-if="returned.status==='pending'" class="mt-1 text-xs">ลิงก์หมดอายุ {{fmtDateTime(returned.expires_at)}} · {{returned.mail_status==='sent'?'ส่งอีเมลแล้ว':returned.mail_status==='failed'?'ส่งอีเมลไม่สำเร็จ':'กำลังส่งอีเมล'}}</p>
      <p v-if="returned.mail_error" role="alert" class="field-error">{{returned.mail_error}}</p>
    </div>
    <div v-if="!mode" class="flex flex-wrap gap-2">
      <button type="button" class="btn-secondary" :disabled="blocked" @click="open('edit')">แก้ไขรายละเอียด</button>
      <button type="button" class="btn-secondary" :disabled="blocked||!ticket.requester_email" @click="open('return')">{{returned?.status==='pending'?'ส่งกลับอีกครั้ง':'ส่งกลับให้ผู้ขอแก้ไข'}}</button>
    </div>
    <p v-if="!ticket.requester_email" class="text-xs text-slate-500 dark:text-slate-400">ไม่มีอีเมลผู้ขอสำหรับส่งลิงก์ Admin สามารถแก้ไขรายละเอียดให้ได้</p>
    <form v-if="mode" class="space-y-4" @submit.prevent="submit">
      <h3 class="font-semibold">{{mode==='edit'?'แก้ไขรายละเอียดคำขอ':'ส่งกลับให้ผู้ขอแก้ไข'}}</h3>
      <PurchaseRequestFields v-if="mode==='edit'" v-model="data" :disabled="saving||props.busy" :errors="errors" />
      <div><label for="request_edit_reason" class="label">{{mode==='edit'?'เหตุผลการแก้ไข':'เหตุผลที่ส่งกลับ / รายละเอียดที่ต้องแก้ไข'}} <span class="text-red-500">*</span></label><textarea id="request_edit_reason" v-model="reason" class="input" maxlength="2000" rows="3" :disabled="saving" required /><p v-if="errors.reason" class="field-error">{{errors.reason}}</p></div>
      <p v-if="mode==='return'" class="text-sm text-slate-500 dark:text-slate-400">ส่งลิงก์ไปยัง {{ticket.requester_email}} เพื่อแก้ไขได้ครั้งเดียวภายใน 7 วัน โดยไม่ต้องลงนามใหม่</p>
      <p v-if="error" role="alert" class="field-error">{{error}}</p>
      <div class="flex flex-wrap gap-2"><button class="btn-primary" :disabled="blocked">{{saving?'กำลังบันทึก...':mode==='edit'?'บันทึกรายละเอียด':'ส่งอีเมลให้ผู้ขอแก้ไข'}}</button><button type="button" class="btn-secondary" :disabled="saving" @click="mode=''">ยกเลิก</button></div>
    </form>
    <details v-if="ticket.request_edit_history?.length" class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700"><summary class="cursor-pointer font-medium">ประวัติแก้ไขรายละเอียด ({{ticket.request_edit_history.length}})</summary><ol class="mt-3 space-y-4"><li v-for="change in ticket.request_edit_history" :key="change.id" class="space-y-1"><p class="font-medium">{{change.admin_name||'ผู้ขอ'}} · {{fmtDateTime(change.created_at)}}</p><p class="whitespace-pre-wrap break-words">{{change.reason}}</p><details><summary class="cursor-pointer text-brand-700 dark:text-brand-300">รายละเอียดก่อนและหลังแก้ไข</summary><dl class="mt-2 space-y-3"><div v-for="row in requestEditChanges(change.before_snapshot,change.after_snapshot)" :key="row.label"><dt class="font-medium">{{row.label}}</dt><dd class="whitespace-pre-wrap break-words text-xs text-slate-500 dark:text-slate-400">ก่อน: {{row.before}}</dd><dd class="whitespace-pre-wrap break-words text-xs">หลัง: {{row.after}}</dd></div></dl></details></li></ol></details>
  </div>
</template>
