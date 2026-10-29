<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, type Paged, type TicketRow } from '@/api'
import type { DocumentDefinition, ITDocument } from '@/documents'
import { documentStates,documentStateLabel } from '@/documents'
import { fmtDate } from '@/format'
import DocumentPrint from '@/components/DocumentPrint.vue'
const forms=ref<Record<string,DocumentDefinition>>({})
const route=useRoute()
const recordMode=computed(()=>route.name==='borrow-records')
const shownForms=computed(()=>recordMode.value?Object.values(forms.value).filter(f=>f.record):Object.values(forms.value))
const list=ref<Paged<ITDocument>|null>(null)
const pendingRequests=ref<Paged<Pick<TicketRow,'id'|'ticket_no'|'title'|'requester_name'|'requester_dept'|'created_at'>>|null>(null)
const code=ref(recordMode.value?'FM-IT-008':''),status=ref(''),q=ref(''),error=ref(''),loading=ref(false)
async function load(page=1){loading.value=true;error.value='';try{const [docs,requests]=await Promise.all([api.get<Paged<ITDocument>>('/admin/documents',{form_code:code.value,status:status.value,q:q.value,page}),recordMode.value?api.get<NonNullable<typeof pendingRequests.value>>('/admin/borrow-requests'):Promise.resolve(null)]);list.value=docs;pendingRequests.value=requests}catch(e){error.value=(e as Error).message}finally{loading.value=false}}
async function queuePage(page:number){try{pendingRequests.value=await api.get('/admin/borrow-requests',{page})}catch(e){error.value=(e as Error).message}}
onMounted(async()=>{try{forms.value=(await api.get<{forms:Record<string,DocumentDefinition>}>('/admin/document-forms')).forms;await load()}catch(e){error.value=(e as Error).message}})
watch(recordMode,()=>{code.value=recordMode.value?'FM-IT-008':'';status.value='';q.value='';load()})
const existing:Record<string,string>={'FM-IT-003':'repair','FM-IT-007':'purchase','FM-IT-009':'email','FM-IT-010':'service'}
const blankForm=ref<DocumentDefinition|null>(null)
const blankPrint=ref<InstanceType<typeof DocumentPrint>|null>(null)
const printing=ref(false)
const blankDocument=computed<ITDocument|null>(()=>blankForm.value?{
  id:0,document_no:'',form_code:blankForm.value.code,title:blankForm.value.title,document_date:'',
  company:blankForm.value.code==='FM-IT-007.1'?'บริษัท อัตถสาร อินโนเวชั่น จำกัด':'บริษัท อัตถสาร จำกัด',
  status:'draft',version:1,data:{fields:{},rows:[],signers:{}},source_note:null,updated_at:'',signatures:[],logs:[],
}:null)
async function printBlank(form:DocumentDefinition){
  if(printing.value)return
  printing.value=true;error.value=''
  try{
    blankForm.value=form
    await nextTick()
    await blankPrint.value?.prepare()
    window.print()
  }catch(e){error.value=(e as Error).message}finally{printing.value=false}
}
</script>
<template>
  <div class="no-print">
  <h1 class="text-2xl font-bold">{{recordMode?'บันทึกเบิก–ยืมอุปกรณ์':'เอกสารฝ่าย IT'}}</h1>
  <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{recordMode?'FM-IT-008 · Admin IT Hub อนุมัติคำขอก่อนเพิ่มเข้าบันทึก แล้วจึงติดตามการคืนและพิมพ์เอกสาร':'แบบฟอร์ม FM-IT · บันทึกข้อมูล ลงนามตามลำดับ และพิมพ์ตามแบบต้นฉบับ'}}</p>
  <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    <section v-for="form in shownForms" :key="form.code" class="card flex flex-col p-4">
      <p class="font-mono text-sm font-bold text-brand-700 dark:text-brand-300">{{form.code}}</p><h2 class="mt-1 flex-1 font-semibold">{{form.title}}</h2>
      <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{form.record?'คำขอต้องผ่าน Admin IT Hub อนุมัติก่อนเข้าบันทึก':form.roles.length?`ลงนาม ${form.roles.length} ตำแหน่ง`:'บันทึกประวัติ ไม่ต้องอนุมัติ'}} · A4 {{form.orientation==='landscape'?'แนวนอน':'แนวตั้ง'}}</p>
      <div class="mt-3 flex flex-wrap gap-2"><button class="btn-secondary px-3 py-2 text-xs" :disabled="printing" :aria-label="`พิมพ์แบบฟอร์มเปล่า ${form.code}`" @click="printBlank(form)">พิมพ์แบบฟอร์มเปล่า</button><RouterLink :to="form.record?{name:'request',params:{type:'borrow'}}:{name:'document-new',query:{form:form.code}}" class="btn-secondary px-3 py-2 text-xs">{{form.record?'ส่งคำขอเบิก–ยืม':'สร้างเอกสาร'}}</RouterLink><RouterLink v-if="form.record&&!recordMode" :to="{name:'borrow-records'}" class="btn-ghost px-2 py-2 text-xs">ดูบันทึก / คำขอรออนุมัติ</RouterLink><RouterLink v-if="existing[form.code]" :to="{name:'request',params:{type:existing[form.code]}}" class="btn-ghost px-2 py-2 text-xs">ยื่นคำขอบริการ</RouterLink></div>
    </section>
  </div>
  <section v-if="recordMode" class="card mt-6 p-4">
    <div class="flex flex-wrap items-center gap-3"><h2 class="flex-1 font-semibold">คำขอรอ Admin IT Hub อนุมัติ ({{pendingRequests?.total||0}})</h2><button class="btn-secondary" :disabled="loading" @click="load()">ตรวจคำขอใหม่</button></div>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">คำขอส่วนนี้ยังไม่เข้าบันทึก FM-IT-008 เมื่ออนุมัติระบบจะเพิ่มให้อัตโนมัติ</p>
    <div class="table-wrap mt-3"><table class="table"><thead><tr><th>เลขที่คำขอ</th><th>ผู้ยืม / แผนก</th><th>อุปกรณ์</th><th /></tr></thead><tbody><tr v-for="request in pendingRequests?.data" :key="request.id"><td class="font-mono">{{request.ticket_no}}</td><td>{{request.requester_name}}<p class="text-xs text-slate-500 dark:text-slate-400">{{request.requester_dept}}</p></td><td>{{request.title}}</td><td><RouterLink :to="{name:'ticket',params:{id:request.id}}" class="whitespace-nowrap text-brand-700 dark:text-brand-300">ตรวจสอบ / อนุมัติ</RouterLink></td></tr><tr v-if="!pendingRequests?.data.length"><td colspan="4" class="py-6 text-center text-slate-400">{{loading?'กำลังโหลด...':'ไม่มีคำขอรออนุมัติ'}}</td></tr></tbody></table></div>
    <div v-if="pendingRequests&&pendingRequests.total>pendingRequests.per_page" class="mt-3 flex justify-end gap-3"><button class="btn-secondary" :disabled="pendingRequests.page<=1" @click="queuePage(pendingRequests.page-1)">ก่อนหน้า</button><button class="btn-secondary" :disabled="pendingRequests.page*pendingRequests.per_page>=pendingRequests.total" @click="queuePage(pendingRequests.page+1)">ถัดไป</button></div>
  </section>
  <h2 v-if="recordMode" class="mt-6 font-semibold">บันทึกที่อนุมัติแล้ว</h2>
  <form class="card mt-6 flex flex-wrap gap-3 p-4" @submit.prevent="load()">
    <input v-model="q" aria-label="ค้นหาเอกสาร" placeholder="เลขที่ / ชื่อเอกสาร / บริษัท / ผู้ยืม / อุปกรณ์" class="input min-w-48 flex-1" />
    <select v-if="!recordMode" v-model="code" class="input sm:w-44" aria-label="แบบฟอร์ม"><option value="">ทุกแบบฟอร์ม</option><option v-for="f in forms" :key="f.code" :value="f.code">{{f.code}}</option></select>
    <select v-model="status" class="input sm:w-48" aria-label="สถานะเอกสาร"><option value="">ทุกสถานะ</option><template v-if="recordMode"><option value="in_progress">บันทึกแล้ว</option><option value="cancelled">ยกเลิก</option></template><template v-else><option v-for="(label,key) in documentStates" :key="key" :value="key">{{label}}</option></template></select><button class="btn-primary" :disabled="loading">ค้นหา</button>
  </form>
  <p v-if="error" role="alert" class="mt-3 rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{error}}</p>
  <div class="card table-wrap mt-4"><table class="table"><thead><tr><th>เลขที่ / แบบฟอร์ม</th><th>เอกสาร</th><th>วันที่</th><th>สถานะ</th><th /></tr></thead><tbody>
    <tr v-for="d in list?.data" :key="d.id"><td><RouterLink :to="{name:'document-detail',params:{id:d.id}}" class="font-mono text-brand-700 dark:text-brand-300">{{d.document_no}}</RouterLink><p class="text-xs text-slate-400">{{d.form_code}}</p></td><td>{{d.title}}<p class="text-xs text-slate-500 dark:text-slate-400">{{d.company}}</p><span v-if="d.source_note" class="text-xs text-amber-700 dark:text-amber-300">มีข้อมูลจากต้นทาง / ฉบับก่อนหน้า</span></td><td class="whitespace-nowrap">{{fmtDate(d.document_date)}}</td><td class="whitespace-nowrap">{{documentStateLabel(d)}}<p v-if="d.record_summary" class="text-xs text-slate-500 dark:text-slate-400">ยืมอยู่ {{d.record_summary.outstanding}} · คืนแล้ว {{d.record_summary.returned}} รายการ</p></td><td><RouterLink :to="{name:'document-detail',params:{id:d.id}}" class="text-brand-700 dark:text-brand-300">เปิด</RouterLink></td></tr>
    <tr v-if="!list?.data.length"><td colspan="5" class="py-8 text-center text-slate-400">{{loading?'กำลังโหลด...':'ยังไม่มีเอกสาร'}}</td></tr>
  </tbody></table></div>
  <div v-if="list" class="mt-4 flex items-center justify-between text-sm"><span>ทั้งหมด {{list.total}} เอกสาร</span><div class="flex gap-3"><button class="btn-secondary" :disabled="list.page<=1||loading" @click="load(list.page-1)">ก่อนหน้า</button><button class="btn-secondary" :disabled="list.page*list.per_page>=list.total||loading" @click="load(list.page+1)">ถัดไป</button></div></div>
  </div>
  <DocumentPrint v-if="blankForm&&blankDocument" :key="blankForm.code" ref="blankPrint" :document="blankDocument" :definition="blankForm" blank />
</template>
