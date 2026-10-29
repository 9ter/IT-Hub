<script setup lang="ts">
import { computed,reactive,ref,watch } from 'vue'
import { api,type Approver,type Ticket,type PurchaseFields,type PurchaseRole } from '@/api'
import { fmtDateTime } from '@/format'
import { toast } from '@/store'
const props=defineProps<{ticket:Ticket;resolution:string;approvers:Approver[];ownSignatureAvailable:boolean;busy:boolean}>()
const fields=defineModel<PurchaseFields>({required:true})
const emit=defineEmits<{updated:[ticket:Ticket,preserveDraft:boolean];busy:[busy:boolean]}>()
const operation=ref(''),error=ref('')
const selected=reactive<Record<PurchaseRole,number|''>>({director:'',purchasing:'',md:''})
const keys=['director','purchasing','md'] as const
const labels={director:'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง',purchasing:'ผู้ตรวจสอบ (ผจก.ฝ่ายจัดซื้อ)',md:'ผู้ช่วยกรรมการผู้จัดการ'}
const roles=computed(()=>keys.map(key=>({key,label:props.ticket.purchase_signing_roles?.[key].label||labels[key]})))
const headBlocked=computed(()=>!!props.ticket.approval&&props.ticket.approval.status!=='approved')
const available=computed(()=>!headBlocked.value&&!['rejected','cancelled'].includes(props.ticket.status))
const busy=computed(()=>props.busy||!!operation.value)
const normalize=(v:PurchaseFields|undefined)=>Object.fromEntries(['vendor','vendor_phone','quotation','quotation_date','amount','vat'].map(k=>[k,(v?.[k as keyof PurchaseFields]??'').toString().trim()]))
const dirty=computed(()=>JSON.stringify(normalize(fields.value))!==JSON.stringify(normalize(props.ticket.purchase_fields))||props.resolution.trim()!==(props.ticket.resolution||''))
const entry=(role:PurchaseRole)=>props.ticket.purchase_approvals?.[role]
watch(()=>props.ticket.id,()=>{for(const key of keys)selected[key]=entry(key)?.employee_id||(key==='director'?props.ticket.default_completion_approver_id||'':'')},{immediate:true})
async function perform(kind:string,role?:PurchaseRole){
  if(busy.value)return
  error.value='';operation.value=kind;emit('busy',true)
  try{
    let updated:Ticket
    if(kind==='refresh'){
      if(dirty.value){error.value='กรุณาบันทึกข้อมูลที่แก้ไขก่อนตรวจสถานะ';return}
      updated=await api.get(`/admin/tickets/${props.ticket.id}`)
    }else if(kind==='receipt')updated=await api.post(`/admin/tickets/${props.ticket.id}/receipt-signature`,{consent:true})
    else updated=await api.post(`/admin/tickets/${props.ticket.id}/purchase-signature`,{role,approver_id:selected[role!],resolution:props.resolution,purchase_fields:fields.value,work_revision:props.ticket.completion_revision})
    emit('updated',updated,kind==='receipt')
    if(kind==='request'){
      const sent=updated.purchase_approvals?.[role!]
      toast(sent?.mail_status==='sent'?'ส่งอีเมลขอลงนามแล้ว':sent?.mail_error||'บันทึกแล้ว แต่ส่งอีเมลไม่สำเร็จ',sent?.mail_status==='sent'?'ok':'error')
    }else if(kind==='receipt')toast('บันทึกลายเซ็นผู้รับเรื่องแล้ว')
  }catch(e){error.value=(e as Error).message}finally{operation.value='';emit('busy',false)}
}
</script>
<template>
  <section class="card space-y-3 p-5">
    <h2 class="font-semibold">ผู้รับเรื่อง (ฝ่ายเทคโนโลยีสารสนเทศ) · Admin IT Hub</h2>
    <template v-if="ticket.receipt_signature"><img :src="ticket.receipt_signature.signature_data" alt="ลายเซ็นผู้รับเรื่อง IT" class="h-24 max-w-full rounded bg-white object-contain" /><p class="font-medium">{{ticket.receipt_signature.signer_name}}</p><p class="text-sm text-slate-500 dark:text-slate-400">ลงนามเมื่อ {{fmtDateTime(ticket.receipt_signature.signed_at)}}</p></template>
    <template v-else>
      <p class="text-sm text-slate-600 dark:text-slate-300">เมื่อกดรับเรื่องหรือเริ่มดำเนินการ ระบบจะเก็บลายเซ็นประจำบัญชีของ Admin ผู้กดไว้ในช่องผู้รับเรื่อง กดลงนามได้ทันทีโดยไม่ต้องกรอกผลดำเนินการ</p>
      <RouterLink to="/admin/admins" class="inline-block text-sm text-brand-700 dark:text-brand-300">{{ownSignatureAvailable?'จัดการลายเซ็นของฉัน':'เพิ่มลายเซ็นของฉันในหน้าผู้ดูแลระบบ'}}</RouterLink>
      <button class="btn-secondary" :disabled="busy||!available||!ownSignatureAvailable" @click="perform('receipt')">{{operation==='receipt'?'กำลังลงนาม...':'ลงนามผู้รับเรื่องด้วยลายเซ็นของฉัน'}}</button>
    </template>
  </section>
  <section class="card space-y-4 p-5">
    <h2 class="font-semibold">ข้อมูลจัดซื้อและลายเซ็นเพิ่มเติม FM-IT-007</h2>
    <p class="rounded-lg bg-blue-50 p-3 text-sm text-blue-900 dark:bg-blue-950/50 dark:text-blue-100">IT เลือกผู้ลงนามและส่งอีเมลได้แยกกันทั้ง 3 ตำแหน่ง ปิดงานได้โดยไม่ต้องรอลายเซ็นครบ และผู้รับลงนามต่อหลังปิดงานได้</p>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label class="label" for="purchase_vendor">ซื้อจากบริษัท / ร้าน</label><input id="purchase_vendor" v-model="fields.vendor" class="input" maxlength="200" :disabled="busy||!available" /></div>
      <div><label class="label" for="purchase_vendor_phone">โทรศัพท์ผู้ขาย</label><input id="purchase_vendor_phone" v-model="fields.vendor_phone" class="input" maxlength="50" :disabled="busy||!available" /></div>
      <div><label class="label" for="purchase_quotation">ใบเสนอราคาเลขที่</label><input id="purchase_quotation" v-model="fields.quotation" class="input" maxlength="150" :disabled="busy||!available" /></div>
      <div><label class="label" for="purchase_quotation_date">วันที่ใบเสนอราคา</label><input id="purchase_quotation_date" v-model="fields.quotation_date" type="date" class="input" :disabled="busy||!available" /></div>
      <div><label class="label" for="purchase_amount">รวมเป็นเงิน (บาท)</label><input id="purchase_amount" v-model="fields.amount" type="text" inputmode="decimal" class="input" :disabled="busy||!available" /></div>
      <div><label class="label" for="purchase_vat">VAT</label><select id="purchase_vat" v-model="fields.vat" class="input" :disabled="busy||!available"><option value="">ยังไม่ระบุ</option><option value="excluded">ราคาไม่รวม VAT</option><option value="included">ราคารวม VAT</option></select></div>
    </div>
    <p class="text-xs text-slate-500 dark:text-slate-400">กดบันทึกใบงานหรือส่งขอลายเซ็นเพื่อเก็บข้อมูลนี้ การแก้ข้อมูลจัดซื้อหรือผลดำเนินการจะต้องขอลายเซ็นใหม่สำหรับฉบับที่แก้ไข</p>
    <p v-if="headBlocked" class="text-sm text-amber-700 dark:text-amber-200">รอผู้มีอำนาจต้นสังกัดอนุมัติก่อนส่งอีก 3 ลายเซ็น</p>
    <div v-for="role in roles" :key="role.key" class="space-y-2 border-t border-slate-200 pt-4 dark:border-slate-700">
      <h3 class="font-medium">{{role.label}}</h3>
      <template v-if="entry(role.key)">
        <p class="text-sm">{{entry(role.key)!.approver_name}}</p>
        <p v-if="!entry(role.key)!.current" class="text-sm text-amber-700 dark:text-amber-200">ลายเซ็นหรือคำขอฉบับก่อน — ข้อมูลเปลี่ยนแล้ว กรุณาส่งขอลงนามใหม่</p>
        <p v-else class="text-sm text-brand-700 dark:text-brand-300">{{{pending:'รอลงนาม',approved:'ลงนามแล้ว',rejected:'ไม่รับรองเอกสาร'}[entry(role.key)!.status]}}</p>
        <p v-if="entry(role.key)!.mail_error" class="field-error">{{entry(role.key)!.mail_error}}</p>
        <p v-else-if="entry(role.key)!.mail_sent_at" class="text-xs text-slate-500 dark:text-slate-400">ส่งอีเมล {{fmtDateTime(entry(role.key)!.mail_sent_at)}} · หมดอายุ {{fmtDateTime(entry(role.key)!.expires_at)}}</p>
        <img v-if="entry(role.key)!.current&&entry(role.key)!.signature_data" :src="entry(role.key)!.signature_data!" :alt="`ลายเซ็น ${role.label}`" class="h-20 max-w-full rounded bg-white object-contain" />
        <p v-if="entry(role.key)!.signed_at" class="text-xs text-slate-500 dark:text-slate-400">ลงนาม {{fmtDateTime(entry(role.key)!.signed_at)}}</p><p v-if="entry(role.key)!.decision_note" class="whitespace-pre-wrap text-sm [overflow-wrap:anywhere]">{{entry(role.key)!.decision_note}}</p>
      </template>
      <template v-if="!entry(role.key)?.current||entry(role.key)?.status!=='approved'||dirty">
        <label class="label" :for="`purchase_signer_${role.key}`">ผู้รับอีเมลขอลงนาม</label>
        <select :id="`purchase_signer_${role.key}`" v-model="selected[role.key]" class="input" :disabled="busy||!available"><option value="" disabled>เลือกผู้ลงนาม</option><option v-for="p in approvers" :key="p.id" :value="p.id">{{p.full_name}} · {{p.department||p.company}}</option></select>
        <button class="btn-secondary" :disabled="busy||!available||!selected[role.key]" @click="perform('request',role.key)">{{operation==='request'?'กำลังส่ง...':entry(role.key)?.current&&entry(role.key)?.status==='pending'&&!dirty?'ส่งอีเมลขอลายเซ็นใหม่':'บันทึกข้อมูลและส่งขอลายเซ็น'}}</button>
      </template>
    </div>
    <button class="btn-secondary" :disabled="busy" @click="perform('refresh')">ตรวจสถานะลายเซ็นจัดซื้อ</button><p v-if="error" role="alert" class="field-error">{{error}}</p>
  </section>
</template>
