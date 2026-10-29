<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute,useRouter } from 'vue-router'
import { api,ApiError,type Employee,type AdminRow } from '@/api'
import { auth,toast } from '@/store'
import { todayInput,fmtDateTime } from '@/format'
import { documentStateLabel,type DocumentDefinition,type ITDocument,type DocumentField } from '@/documents'
import DocumentInput from '@/components/DocumentInput.vue'
import DocumentPrint from '@/components/DocumentPrint.vue'
const route=useRoute(),router=useRouter()
const forms=ref<Record<string,DocumentDefinition>>({}),people=ref<Employee[]>([]),admins=ref<AdminRow[]>([])
const doc=ref<ITDocument|null>(null),error=ref(''),busy=ref(false),loaded=ref(false),dirty=ref(false),preview=ref(false),consent=ref(false)
const errors=ref<Record<string,string>>({}),printRef=ref<InstanceType<typeof DocumentPrint>|null>(null)
const assetCompany=ref('ATS'),signDecision=ref('approved'),signNote=ref('')
const def=computed(()=>doc.value?forms.value[doc.value.form_code]:undefined)
const isRecord=computed(()=>!!def.value?.record)
const editable=computed(()=>doc.value && ['draft','in_progress'].includes(doc.value.status))
const requestLocked=computed(()=>!editable.value||(!isRecord.value&&doc.value?.status!=='draft'))
const returnedCount=computed(()=>doc.value?.data.rows.filter(r=>r.returned_at).length||0)
const pending=computed(()=>doc.value?.signatures.find(s=>s.status==='pending'))
const myTurn=computed(()=>pending.value?.admin_id===auth.admin?.id)
const phase=computed(()=>doc.value?.status==='draft'&&def.value?.roles.some(r=>r.phase==='request')?'request':'completion')
const templateUrl=computed(()=>`${import.meta.env.BASE_URL}api/admin/document-templates/${encodeURIComponent(doc.value?.form_code||'')}`)
function fieldDisabled(f:DocumentField){return !editable.value || (requestLocked.value&&f.phase==='request') || (isRecord.value&&f.key!=='notes')}
watch(()=>route.fullPath,async()=>{
  loaded.value=false;error.value='';doc.value=null;dirty.value=false;consent.value=false
  assetCompany.value=route.query.company==='INNO'?'INNO':'ATS';signDecision.value='approved';signNote.value=''
  try{
    const [catalog,employees,accounts]=await Promise.all([api.get<{forms:Record<string,DocumentDefinition>}>('/admin/document-forms'),api.get<Employee[]>('/admin/employees'),api.get<AdminRow[]>('/admin/admins')])
    forms.value=catalog.forms;people.value=employees.filter(e=>e.is_active&&e.email);admins.value=accounts.filter(a=>a.is_active)
    if(route.params.id){doc.value=await api.get<ITDocument>(`/admin/documents/${route.params.id}`)}
    else{
      const code=typeof route.query.form==='string'&&forms.value[route.query.form]?route.query.form:'FM-IT-011'
      const definition=forms.value[code]!
      if(definition.record){await router.replace({name:'request',params:{type:'borrow'}});return}
      doc.value={id:0,document_no:'(ฉบับร่างใหม่)',form_code:code,title:definition.title,document_date:todayInput(),company:code==='FM-IT-007.1'||route.query.company==='INNO'?'บริษัท อัตถสาร อินโนเวชั่น จำกัด':'บริษัท อัตถสาร จำกัด',status:'draft',version:1,data:{fields:{},rows:[],signers:{}},source_note:null,updated_at:'',signatures:[],logs:[]}
      const f=doc.value.data.fields
      if(definition.fields.some(c=>c.key==='year'))f.year=String(new Date().getFullYear()+543)
      if(definition.record){doc.value.document_no='(บันทึกใหม่)';f.start_date=todayInput();doc.value.data.rows=[newRecordRow()]}
      if(code==='FM-IT-006'){f.team='ฝ่ายเทคโนโลยีสารสนเทศ';doc.value.data.rows=[{task:'ตรวจสอบอาการและซ่อมบำรุงเมื่อพบปัญหา',frequency:'เมื่อพบปัญหา',responsible:'เจ้าหน้าที่ IT'},{task:'บำรุงรักษาคอมพิวเตอร์และอุปกรณ์ต่อพ่วงเชิงป้องกัน',frequency:'2 ครั้ง/เดือน',responsible:'เจ้าหน้าที่ IT'},{task:'สำรองข้อมูล ตรวจสอบผล และสรุปรายงาน',frequency:'1 ครั้ง/สัปดาห์',responsible:'เจ้าหน้าที่ IT'}]}
      const director=people.value.find(p=>p.full_name.replace(/\s+/g,' ').trim()==='สมไชย อักษร')
      for(const role of definition.roles){doc.value.data.signers[role.key]=role.kind==='admin'?{id:auth.admin?.id}:role.kind==='external'?{name:'',email:''}:{id:role.key==='director'?(director?.id||null):null}}
      if(route.query.ticket||route.query.asset){await prefill()}
    }
  }catch(e){error.value=(e as Error).message}finally{loaded.value=true}
},{immediate:true})
async function prefill(){
  if(!doc.value)return
  try{
    const d=await api.get<{data:ITDocument['data'];source_note:string}>('/admin/document-prefill',{form_code:doc.value.form_code,company:doc.value.form_code==='FM-IT-001'?assetCompany.value:undefined,ticket_id:String(route.query.ticket||''),asset_id:String(route.query.asset||'')})
    doc.value.data.fields={...doc.value.data.fields,...d.data.fields};doc.value.data.rows=d.data.rows.map(row=>isRecord.value?{...row,issuer:auth.admin?.display_name||''}:row);doc.value.source_note=d.source_note;dirty.value=true
    if(doc.value.form_code==='FM-IT-001')doc.value.company=assetCompany.value==='INNO'?'บริษัท อัตถสาร อินโนเวชั่น จำกัด':assetCompany.value==='ATS'?'บริษัท อัตถสาร จำกัด':'บริษัท อัตถสาร จำกัด / บริษัท อัตถสาร อินโนเวชั่น จำกัด'
  }catch(e){error.value=(e as Error).message}
}
function newRecordRow(){return {quantity:'1',date:doc.value?.data.fields.start_date||todayInput(),issuer:auth.admin?.display_name||''}}
function addRow(){if(doc.value){doc.value.data.rows.push(isRecord.value?newRecordRow():{});dirty.value=true}}
function receiveRow(i:number){const row=doc.value?.data.rows[i];if(row){row.returned_at=todayInput();row.returner=row.user||'';row.receiver=auth.admin?.display_name||'';dirty.value=true}}
async function save(navigate=true){
  if(!doc.value||!editable.value)return false
  error.value='';errors.value={}
  try{
    const wasNew=!doc.value.id
    doc.value=wasNew?await api.post<ITDocument>('/admin/documents',doc.value):await api.put<ITDocument>(`/admin/documents/${doc.value.id}`,doc.value)
    dirty.value=false
    if(wasNew&&navigate)await router.replace({name:'document-detail',params:{id:doc.value.id}})
    return true
  }catch(e){error.value=(e as Error).message;if(e instanceof ApiError)errors.value=e.errors;return false}
}
async function action(kind:'save'|'submit'|'copy'|'cancel'|'resend'|'sign'|'refresh'){
  if(busy.value||!doc.value)return
  busy.value=true;error.value=''
  try{
    if(kind==='save'){if(await save())toast(isRecord.value?'บันทึกข้อมูลเบิก–คืนแล้ว':'บันทึกเอกสารแล้ว');return}
    if(kind==='refresh'){if(dirty.value){error.value='กรุณาบันทึกข้อมูลที่แก้ไขก่อนตรวจสถานะ';return}doc.value=await api.get(`/admin/documents/${doc.value.id}`);return}
    if(kind==='submit'){
      if(!(await save(false)))return
      doc.value=await api.post(`/admin/documents/${doc.value!.id}/submit`,{version:doc.value!.version})
      if(!route.params.id)await router.replace({name:'document-detail',params:{id:doc.value!.id}})
      toast('บันทึกและเข้าสู่ขั้นตอนลงนามแล้ว');return
    }
    if(kind==='sign'&&!consent.value){error.value='กรุณายืนยันว่าตรวจสอบเอกสารและยินยอมลงนาม';return}
    if(kind==='copy'&&dirty.value){error.value='กรุณาบันทึกข้อมูลที่แก้ไขก่อนสร้างฉบับใหม่';return}
    if(kind==='cancel'&&!confirm(isRecord.value?'ยกเลิกบันทึกเบิก–ยืมนี้?':'ยกเลิกเอกสารนี้และระงับลิงก์ที่ยังไม่ได้ลงนาม?'))return
    doc.value=await api.post(`/admin/documents/${doc.value.id}/${kind}`,kind==='sign'?{decision:signDecision.value,decision_note:signNote.value,consent:consent.value}:{})
    consent.value=false
    if(kind==='copy')await router.push({name:'document-detail',params:{id:doc.value!.id}})
  }catch(e){error.value=(e as Error).message}finally{busy.value=false}
}
async function print(){if(!doc.value)return;if(dirty.value||!doc.value.id){error.value=isRecord.value?'กรุณาบันทึกข้อมูลก่อนพิมพ์':'กรุณาบันทึกฉบับร่างก่อนพิมพ์';return}await printRef.value?.prepare();window.print()}
</script>
<template>
  <div class="no-print">
    <RouterLink :to="{name:isRecord?'borrow-records':'documents'}" class="text-sm text-brand-700 dark:text-brand-300">← {{isRecord?'บันทึกเบิก–ยืมอุปกรณ์':'เอกสารฝ่าย IT'}}</RouterLink>
    <p v-if="!loaded" class="mt-6">กำลังโหลดเอกสาร...</p>
    <template v-if="doc&&def">
      <div class="mt-4 flex flex-wrap items-center gap-3"><div class="min-w-0 flex-1"><p class="font-mono text-sm text-slate-500 dark:text-slate-400">{{doc.document_no}} · {{doc.form_code}}</p><h1 class="text-xl font-bold">{{def.title}}</h1></div><span class="rounded-lg bg-blue-50 dark:bg-blue-900/40 px-3 py-1 text-sm text-blue-800 dark:text-blue-300">{{documentStateLabel(doc)}}</span><button class="btn-secondary" @click="print">พิมพ์เอกสาร</button></div>
      <p v-if="isRecord" class="mt-4 rounded-xl bg-blue-50 dark:bg-blue-900/40 p-4 text-sm text-blue-900 dark:text-blue-300">บันทึกการเบิก–ยืมที่ผ่าน Admin IT Hub อนุมัติแล้ว เพิ่ม Serial และบันทึกการคืนได้ · ยืมอยู่ {{doc.data.rows.length-returnedCount}} รายการ · คืนแล้ว {{returnedCount}} รายการ</p>
      <p v-if="doc.borrow_source" class="mt-3 text-sm">อนุมัติโดย {{doc.borrow_source.admin_name}} · {{fmtDateTime(doc.borrow_source.decided_at)}} · <RouterLink :to="{name:'ticket',params:{id:doc.borrow_source.ticket_id}}" class="text-brand-700 dark:text-brand-300">{{doc.borrow_source.ticket_no}} · เปิดคำขอต้นทาง</RouterLink></p>
      <div v-if="doc.source_note" class="mt-4 rounded-xl bg-amber-50 dark:bg-amber-900/40 p-4 text-sm whitespace-pre-wrap text-amber-900 dark:text-amber-300">{{doc.source_note}}<p v-if="!isRecord">ข้อมูลต้นทางเป็นฉบับร่าง ต้องตรวจทานก่อนส่งลงนาม ไม่มีการนำชื่อเดิมมาใช้แทนลายเซ็น</p></div>
      <a :href="templateUrl" class="mt-3 inline-block text-sm text-brand-700 dark:text-brand-300">ดาวน์โหลด Excel ต้นฉบับเพื่อตรวจสอบรูปแบบและข้อมูล</a>
      <p v-if="requestLocked&&editable" class="mt-4 rounded-xl bg-blue-50 dark:bg-blue-900/40 p-4 text-sm text-blue-900 dark:text-blue-300">คำขอลงนามครบแล้ว กรอกผลดำเนินการและเลือกผู้รับรองผล จากนั้นส่งขอลงนามขั้นสุดท้าย</p>
      <form class="mt-5 space-y-5" @submit.prevent="action('save')" @input="dirty=true" @change="dirty=true">
        <section class="card grid gap-4 p-5 sm:grid-cols-2">
          <div class="sm:col-span-2"><label class="label" for="doc-title">ชื่อเอกสาร</label><input id="doc-title" v-model="doc.title" :disabled="requestLocked" class="input" maxlength="200" /></div>
          <div><label class="label" for="doc-date">วันที่เอกสาร</label><input id="doc-date" v-model="doc.document_date" type="date" :disabled="requestLocked" class="input" /></div><div><label class="label" for="doc-company">บริษัท</label><input id="doc-company" v-model="doc.company" :disabled="requestLocked" class="input" maxlength="200" /></div>
        </section>
        <section v-for="stage in (['request','completion'] as const)" v-show="def.fields.some(f=>f.phase===stage)" :key="stage" class="card p-5">
          <h2 class="mb-4 font-semibold">{{isRecord?'ข้อมูลการเบิก–ยืม':stage==='request'?'ข้อมูลเอกสาร / คำขอ':'ผลการดำเนินการ'}}</h2>
          <div class="grid gap-4 sm:grid-cols-2"><div v-for="f in def.fields.filter(f=>f.phase===stage)" :key="f.key" :class="['textarea','multiselect'].includes(f.type)?'sm:col-span-2':''"><label :for="`doc-${f.key}`" class="label">{{f.label}} <span v-if="f.required" class="text-red-500 dark:text-red-400">*</span></label><DocumentInput :id="`doc-${f.key}`" v-model="doc.data.fields[f.key]" :field="f" :disabled="fieldDisabled(f)" /><p v-if="errors[f.key]" class="field-error">{{errors[f.key]}}</p></div></div>
        </section>
        <section v-if="def.columns.length" class="card p-5">
          <div class="mb-4 flex flex-wrap items-center gap-3"><h2 class="flex-1 font-semibold">รายการในเอกสาร ({{doc.data.rows.length}})</h2><template v-if="doc.form_code==='FM-IT-001'&&doc.status==='draft'"><select v-model="assetCompany" class="input sm:w-44" aria-label="บริษัทที่ดึงทะเบียน"><option value="ATS">ATS</option><option value="INNO">INNO</option><option value="">ทุกบริษัท</option></select><button type="button" class="btn-secondary" :disabled="busy" @click="prefill">ดึงทะเบียนอุปกรณ์ปัจจุบัน</button></template><button v-if="editable&&!requestLocked&&!isRecord" type="button" class="btn-secondary" @click="addRow">+ เพิ่มแถว</button></div>
          <p v-if="doc.form_code==='FM-IT-002'" class="mb-3 text-sm text-slate-500 dark:text-slate-400">ผลแต่ละเดือน: / ปกติ · X ผิดปกติ · ✓ แก้ไขแล้ว ช่องว่างหมายถึงยังไม่บันทึกผล</p>
          <p v-if="doc.form_code==='FM-IT-006'" class="mb-3 text-sm text-slate-500 dark:text-slate-400">กรอกแต่ละเดือน เช่น P:1,3 A:1 (P = แผน, A = ทำจริง; สัปดาห์ 1: วันที่ 1–7, 2: 8–14, 3: 15–21, 4: 22–31)</p>
          <div v-if="isRecord" class="space-y-5">
            <article v-for="(row,i) in doc.data.rows" :key="i" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
              <div class="mb-4 flex flex-wrap items-center gap-3"><h3 class="flex-1 font-semibold">รายการที่ {{i+1}} · {{row.returned_at?'คืนแล้ว':'ยืมอยู่'}}</h3></div>
              <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><div v-for="c in def.columns.filter(c=>!['returned_at','returner','receiver','return_condition'].includes(c.key))" :key="c.key"><label :for="`row-${i}-${c.key}`" class="label">{{c.label}}<span v-if="c.required" class="text-red-500"> *</span></label><DocumentInput :id="`row-${i}-${c.key}`" v-model="row[c.key]" :field="c" :disabled="!editable||['department','user','purpose','item','quantity','device_kind'].includes(c.key)" /></div></div>
              <details :open="!!row.returned_at" class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-4"><summary class="cursor-pointer font-medium">บันทึกการคืน</summary><button v-if="editable&&!row.returned_at" type="button" class="btn-secondary mt-3" @click="receiveRow(i)">รับคืนวันนี้</button><p class="my-3 text-xs text-slate-500 dark:text-slate-400">เมื่อรับคืน ระบุวันที่ ผู้คืน เจ้าหน้าที่ผู้รับคืน และสภาพอุปกรณ์ให้ครบ แล้วกดบันทึกข้อมูล</p><div class="grid gap-4 sm:grid-cols-2"><div v-for="c in def.columns.filter(c=>['returned_at','returner','receiver','return_condition'].includes(c.key))" :key="c.key"><label :for="`row-${i}-${c.key}`" class="label">{{c.label}}</label><DocumentInput :id="`row-${i}-${c.key}`" v-model="row[c.key]" :field="c" :disabled="!editable" /></div></div></details>
            </article>
            <p v-if="!doc.data.rows.length" class="text-sm text-slate-500 dark:text-slate-400">เพิ่มรายการอุปกรณ์อย่างน้อย 1 รายการ</p>
          </div>
          <div v-else class="overflow-x-auto"><table class="table"><thead><tr><th>#</th><th v-for="c in def.columns" :key="c.key">{{c.label}}<span v-if="c.required"> *</span></th><th /></tr></thead><tbody><tr v-for="(row,i) in doc.data.rows" :key="i"><td>{{i+1}}</td><td v-for="c in def.columns" :key="c.key" :style="{minWidth:c.key.startsWith('m')&&/^m\d+$/.test(c.key)?'130px':'180px'}"><DocumentInput v-model="row[c.key]" :field="c" :disabled="!editable||requestLocked" /></td><td><button v-if="editable&&!requestLocked" type="button" class="text-red-600 dark:text-red-400" @click="doc.data.rows.splice(i,1);dirty=true">ลบ</button></td></tr><tr v-if="!doc.data.rows.length"><td :colspan="def.columns.length+2" class="text-slate-400">ยังไม่มีรายการ</td></tr></tbody></table></div>
        </section>
        <section v-if="def.roles.length" class="card p-5"><h2 class="mb-4 font-semibold">ผู้ลงนามตามแบบฟอร์ม</h2><p class="mb-4 text-sm text-slate-500 dark:text-slate-400">ระบบจะขอลงนามตามลำดับ ผู้รับต้องตรวจเอกสารและยืนยันด้วยตนเอง เลือกผู้อนุมัติจากทะเบียนพนักงานที่ใช้ร่วมกับ E-Procurement</p>
          <div class="grid gap-4 sm:grid-cols-2"><div v-for="r in def.roles" :key="r.key"><label :for="`signer-${r.key}`" class="label">{{r.label}} <span class="text-xs text-slate-400">{{r.phase==='request'?'ขั้นคำขอ':'ขั้นรับรอง'}}</span></label>
            <div v-if="r.kind==='external'" class="space-y-2"><input v-model="doc.data.signers[r.key]!.name" class="input" aria-label="ชื่อผู้มาติดต่อที่ลงนาม" placeholder="ชื่อผู้ลงนาม" :disabled="!editable" /><input v-model="doc.data.signers[r.key]!.email" type="email" class="input" aria-label="อีเมลผู้มาติดต่อที่ลงนาม" placeholder="E-mail รับลิงก์ลงนาม" :disabled="!editable" /></div>
            <select v-else-if="r.kind==='admin'" :id="`signer-${r.key}`" v-model="doc.data.signers[r.key]!.id" class="input" :disabled="!editable||(requestLocked&&r.phase==='request')"><option :value="null">เลือกเจ้าหน้าที่</option><option v-for="a in admins" :key="a.id" :value="a.id">{{a.display_name}}{{a.id===auth.admin?.id?' (ฉัน)':''}}</option></select>
            <select v-else :id="`signer-${r.key}`" v-model="doc.data.signers[r.key]!.id" class="input" :disabled="!editable||(requestLocked&&r.phase==='request')"><option :value="null">เลือกผู้ลงนาม</option><option v-for="p in people.filter(p=>!r.authority||p.can_approve)" :key="p.id" :value="p.id">{{p.full_name}} · {{p.department||p.company}}</option></select>
          </div></div>
        </section>
        <div v-if="editable" class="flex flex-wrap gap-3"><button :class="isRecord?'btn-primary':'btn-secondary'" :disabled="busy">{{isRecord?'บันทึกข้อมูล':'บันทึกฉบับร่าง'}}{{dirty?' *':''}}</button><button v-if="!isRecord" type="button" class="btn-primary" :disabled="busy" @click="action('submit')">{{def.roles.length?(phase==='request'?'ส่งขอลงนามคำขอ':'ส่งขอลงนามรับรอง'):'บันทึกเอกสารเสร็จสิ้น'}}</button></div>
      </form>
      <section v-if="doc.signatures.length" class="card mt-5 p-5"><div class="flex flex-wrap items-center gap-3"><h2 class="flex-1 font-semibold">การลงนาม</h2><button class="btn-secondary" :disabled="busy" @click="action('refresh')">ตรวจสถานะ</button></div>
        <div v-for="s in doc.signatures" :key="s.id" class="mt-4 border-t border-slate-100 dark:border-slate-700 pt-4"><p class="font-medium">{{s.role_label}} · {{s.signer_name}}</p><p class="text-sm text-slate-500 dark:text-slate-400">{{s.status==='approved'?'ลงนามแล้ว':s.status==='rejected'?'ไม่รับรอง':s.status==='pending'?'ถึงลำดับลงนาม':'รอลำดับ'}}{{s.signed_at?` · ${fmtDateTime(s.signed_at)}`:''}}</p><img v-if="s.signature_data" :src="s.signature_data" alt="ลายเซ็นที่ยืนยันแล้ว" class="bg-white rounded h-20 max-w-full object-contain" /><p v-if="s.decision_note" class="whitespace-pre-wrap text-sm">{{s.decision_note}}</p><p v-if="s.status==='pending'&&s.signer_email" class="text-xs text-slate-500 dark:text-slate-400">อีเมล: {{s.signer_email}} · {{s.mail_status==='sent'?'ส่งแล้ว':s.mail_status==='failed'?'ส่งไม่สำเร็จ':'กำลังรอส่ง'}}</p><p v-if="s.mail_error" class="field-error">{{s.mail_error}}</p></div>
        <div v-if="myTurn&&['request_review','completion_review'].includes(doc.status)" class="mt-4 space-y-3 rounded-xl bg-blue-50 dark:bg-blue-900/40 p-4"><label class="label" for="it-doc-decision">ผลการพิจารณา</label><select id="it-doc-decision" v-model="signDecision" class="input"><option value="approved">ลงนาม / รับรอง</option><option value="rejected">ไม่รับรอง</option></select><label class="label" for="it-doc-note">{{signDecision==='rejected'?'เหตุผลที่ไม่รับรอง *':'หมายเหตุ'}}</label><textarea id="it-doc-note" v-model="signNote" class="input" rows="2" maxlength="2000" /><label class="flex gap-2 text-sm"><input v-model="consent" type="checkbox" />ข้าพเจ้าตรวจสอบเอกสาร และยืนยันใช้ลายเซ็นประจำบัญชีของฉันในตำแหน่ง {{pending?.role_label}}</label><button class="btn-primary" :disabled="busy||!consent||(signDecision==='rejected'&&!signNote.trim())" @click="action('sign')">ยืนยันลงนามด้วยลายเซ็นของฉัน</button><RouterLink to="/admin/admins" class="ml-3 text-sm text-brand-700 dark:text-brand-300">จัดการลายเซ็น</RouterLink></div>
        <button v-if="pending?.signer_email&&['request_review','completion_review'].includes(doc.status)" class="btn-secondary mt-4" :disabled="busy" @click="action('resend')">ส่งอีเมลลำดับปัจจุบันอีกครั้ง</button>
      </section>
      <div v-if="doc.id" class="mt-5 flex flex-wrap gap-3"><button v-if="!isRecord" class="btn-secondary" :disabled="busy" @click="action('copy')">สร้างฉบับใหม่จากเอกสารนี้</button><RouterLink v-else :to="{name:'request',params:{type:'borrow'}}" class="btn-secondary">ส่งคำขอเบิก–ยืมใหม่</RouterLink><button v-if="!['approved','cancelled'].includes(doc.status)" class="btn-ghost text-red-700 dark:text-red-300" :disabled="busy" @click="action('cancel')">ยกเลิกเอกสาร</button><button class="btn-ghost" @click="preview=!preview">{{preview?'ซ่อนตัวอย่าง':'ดูตัวอย่างก่อนพิมพ์'}}</button></div>
      <section v-if="doc.logs.length" class="card mt-5 p-5"><h2 class="mb-3 font-semibold">ประวัติเอกสาร</h2><p v-for="(l,i) in doc.logs" :key="i" class="mt-2 text-sm"><span class="text-xs text-slate-400">{{fmtDateTime(l.created_at)}} · {{l.admin_name||'ระบบ'}}</span><br />{{l.note}}</p></section>
    </template>
    <p v-if="error" role="alert" class="mt-5 rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{error}}</p>
  </div>
  <DocumentPrint v-if="doc&&def" ref="printRef" :document="doc" :definition="def" :preview="preview" />
</template>
