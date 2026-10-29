<script setup lang="ts">
import { computed,ref,watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api'
import { fmtDateTime } from '@/format'
import type { DocumentDefinition,ITDocument } from '@/documents'
import PublicShell from '@/components/PublicShell.vue'
import SignaturePad from '@/components/SignaturePad.vue'
import DocumentPrint from '@/components/DocumentPrint.vue'
interface Review {document:ITDocument;definition:DocumentDefinition;role_label:string;signer_name:string;expires_at:string;saved_signature_data:string|null;saved_signature_hash:string|null}
const route=useRoute(),token=computed(()=>route.hash.slice(1))
const review=ref<Review|null>(null),loading=ref(true),error=ref(''),formError=ref(''),saving=ref(false),result=ref('')
const mode=ref('draw'),signature=ref(''),consent=ref(false),decision=ref('approved'),note=ref('')
const printRef=ref<InstanceType<typeof DocumentPrint>|null>(null)
const ready=computed(()=>consent.value&&(mode.value==='saved'?review.value?.saved_signature_data:signature.value))
watch(token,async value=>{loading.value=true;error.value='';result.value='';review.value=null;consent.value=false;signature.value='';mode.value='draw';note.value='';decision.value='approved';try{review.value=await api.post<Review>('/document-approval/view',{token:value});mode.value=review.value.saved_signature_data?'saved':'draw'}catch(e){error.value=(e as Error).message}finally{loading.value=false}},{immediate:true})
async function submit(){if(saving.value||!ready.value)return;saving.value=true;formError.value='';try{const r=await api.post<{status:string}>('/document-approval/decide',{token:token.value,decision:decision.value,decision_note:note.value,consent:consent.value,signature_data:mode.value==='draw'?signature.value:'',use_saved_signature:mode.value==='saved',saved_signature_hash:review.value?.saved_signature_hash});result.value=r.status;signature.value=''}catch(e){formError.value=(e as Error).message}finally{saving.value=false}}
async function print(){await printRef.value?.prepare();window.print()}
</script>
<template>
  <PublicShell>
    <div class="no-print">
      <h1 class="text-xl font-bold">ตรวจสอบและลงนามเอกสารฝ่าย IT</h1>
      <p v-if="loading" class="card mt-4 p-6">กำลังโหลดเอกสาร...</p><p v-else-if="error" role="alert" class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/40 p-5 text-red-700 dark:text-red-300">{{error}}</p>
      <section v-else-if="result" class="card mt-4 p-6 text-center"><h2 class="text-xl font-bold text-brand-700 dark:text-brand-300">บันทึกการ{{result==='approved'?'ลงนาม / รับรอง':'ไม่อนุมัติ / ไม่รับรอง'}}แล้ว</h2><p class="mt-3">{{review?.document.document_no}} · ระบบเก็บลายเซ็นและวันเวลาแล้ว ลิงก์นี้ใช้ลงนามซ้ำไม่ได้</p></section>
      <template v-else-if="review">
        <section class="card mt-4 space-y-2 p-5"><p class="font-mono text-brand-700 dark:text-brand-300">{{review.document.document_no}}</p><h2 class="font-semibold">{{review.definition.title}}</h2><p>ผู้ลงนาม: {{review.signer_name}}</p><p>ตำแหน่งในเอกสาร: {{review.role_label}}</p><p class="text-sm text-slate-500 dark:text-slate-400">โปรดตรวจเอกสารฉบับเต็มด้านล่างก่อนลงนาม บนมือถือสามารถเลื่อนตัวอย่างในแนวนอนได้</p><button class="btn-secondary" @click="print">พิมพ์ / บันทึก PDF เพื่อตรวจสอบ</button></section>
      </template>
    </div>
    <DocumentPrint v-if="review&&!result" ref="printRef" :document="review.document" :definition="review.definition" preview />
    <form v-if="review&&!result&&!loading" class="card no-print mt-5 space-y-4 p-5" @submit.prevent="submit">
      <div><label class="label" for="document-decision">ผลการพิจารณา</label><select id="document-decision" v-model="decision" class="input"><option value="approved">ลงนาม / รับรอง</option><option value="rejected">ไม่อนุมัติ / ไม่รับรอง</option></select></div>
      <div><label class="label" for="document-note">{{decision==='rejected'?'เหตุผลที่ไม่รับรอง *':'หมายเหตุ'}}</label><textarea id="document-note" v-model="note" class="input" rows="3" maxlength="2000" :required="decision==='rejected'" /></div>
      <div v-if="review.saved_signature_data" class="flex flex-wrap gap-3"><label class="choice"><input v-model="mode" value="saved" type="radio" />ใช้ลายเซ็นจาก E-Procurement</label><label class="choice"><input v-model="mode" value="draw" type="radio" />วาดใหม่</label></div>
      <img v-if="mode==='saved'&&review.saved_signature_data" :src="review.saved_signature_data" alt="ลายเซ็นจาก E-Procurement" class="bg-white rounded mx-auto h-28 max-w-full object-contain" /><SignaturePad v-else v-model="signature" />
      <label class="flex items-start gap-2 text-sm"><input v-model="consent" type="checkbox" class="mt-1" required /><span>ข้าพเจ้า {{review.signer_name}} ได้ตรวจสอบเอกสาร {{review.document.document_no}} และยืนยันใช้ลายเซ็นนี้ในตำแหน่ง {{review.role_label}} เพื่อ{{decision==='approved'?'ลงนาม / รับรอง':'ไม่อนุมัติ / ไม่รับรอง'}}ข้อมูลในฉบับที่แสดงข้างต้น</span></label>
      <p class="text-xs text-slate-500 dark:text-slate-400">ลิงก์หมดอายุ {{fmtDateTime(review.expires_at)}} · การเปิดลิงก์ยังไม่ถือว่าลงนาม</p><p v-if="formError" role="alert" class="field-error">{{formError}}</p><button class="btn-primary w-full" :disabled="saving||!ready">{{saving?'กำลังบันทึก...':'ยืนยันการลงนาม'}}</button>
    </form>
  </PublicShell>
</template>
