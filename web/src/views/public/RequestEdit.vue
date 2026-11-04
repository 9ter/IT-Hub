<script setup lang="ts">
import { ref,watch } from 'vue'
import { useRoute,useRouter } from 'vue-router'
import { api,ApiError,type PurchaseRequestData } from '@/api'
import PublicShell from '@/components/PublicShell.vue'
import PurchaseRequestFields from '@/components/PurchaseRequestFields.vue'
import { purchaseRequestData } from '@/requestEdits'
import { fmtDateTime } from '@/format'
const route=useRoute(),router=useRouter(),token=ref('')
const info=ref<{ticket_no:string;requester_name:string;reason:string;expires_at:string;version:string;request:PurchaseRequestData}|null>(null)
const data=ref(purchaseRequestData({})),loading=ref(true),saving=ref(false),done=ref(false),error=ref(''),formError=ref(''),errors=ref<Record<string,string>>({})
async function load(){
  const requestedToken=token.value
  loading.value=true;error.value='';formError.value='';errors.value={}
  try{const result=await api.post<NonNullable<typeof info.value>>('/request-edit/view',{token:requestedToken});if(token.value===requestedToken){info.value=result;data.value=purchaseRequestData(result.request as unknown as Record<string,unknown>)}}
  catch(e){if(token.value===requestedToken)error.value=(e as Error).message}finally{if(token.value===requestedToken)loading.value=false}
}
async function submit(){
  if(saving.value||!info.value)return
  errors.value={};formError.value=''
  if(!data.value.items.length){errors.value={items:'กรุณาเลือกประเภทอุปกรณ์อย่างน้อย 1 รายการ'};return}
  saving.value=true
  const submittedToken=token.value
  try{await api.post('/request-edit/submit',{token:submittedToken,version:info.value.version,request:data.value});if(token.value===submittedToken)done.value=true}
  catch(e){formError.value=(e as Error).message;if(e instanceof ApiError)errors.value=e.errors}
  finally{saving.value=false}
}
watch(()=>route.hash,(hash)=>{
  if(!hash){if(!token.value){loading.value=false;error.value='ลิงก์ไม่ถูกต้องหรือหมดอายุ'};return}
  token.value=hash.slice(1);done.value=false;info.value=null;formError.value='';errors.value={};load()
  router.replace({path:route.path,query:route.query,hash:''})
},{immediate:true})
</script>
<template>
  <PublicShell back="/">
    <h1 class="text-xl font-bold">แก้ไขรายละเอียดคำขอ</h1>
    <p v-if="loading" class="mt-5 text-sm">กำลังโหลดข้อมูล...</p>
    <p v-else-if="error" role="alert" class="card mt-5 p-5 text-red-600 dark:text-red-300">{{error}}</p>
    <section v-else-if="done" class="card mt-5 space-y-3 p-5"><h2 class="font-semibold text-emerald-700 dark:text-emerald-300">ส่งรายละเอียดที่แก้ไขกลับให้ IT แล้ว</h2><p class="text-sm">ระบบคงลายเซ็นเดิม ไม่ต้องลงนามใหม่</p><RouterLink :to="`/track/${info?.ticket_no}`" class="btn-secondary">ติดตามสถานะคำขอ</RouterLink></section>
    <template v-else-if="info">
      <section class="card mt-5 space-y-2 p-5"><p class="font-mono text-sm">{{info.ticket_no}}</p><p class="font-medium">{{info.requester_name}}</p><p class="whitespace-pre-wrap break-words text-sm">เหตุผลที่ส่งกลับ: {{info.reason}}</p><p class="text-sm text-slate-500 dark:text-slate-400">แก้ไขแล้วส่งกลับได้โดยไม่ต้องลงนามใหม่ · ลิงก์หมดอายุ {{fmtDateTime(info.expires_at)}}</p></section>
      <form class="card mt-4 space-y-4 p-5" @submit.prevent="submit"><PurchaseRequestFields v-model="data" :disabled="saving" :errors="errors" /><p v-if="formError" role="alert" class="field-error">{{formError}}</p><button class="btn-primary w-full" :disabled="saving">{{saving?'กำลังส่ง...':'บันทึกและส่งกลับให้ IT'}}</button><button v-if="formError" type="button" class="btn-secondary w-full" :disabled="saving" @click="load">โหลดข้อมูลล่าสุด</button></form>
    </template>
  </PublicShell>
</template>
