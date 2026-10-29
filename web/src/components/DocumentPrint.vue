<script setup lang="ts">
import { computed,nextTick,onMounted,ref } from 'vue'
import { documentStates,displayDocumentValue,type DocumentDefinition,type ITDocument } from '@/documents'
import { fmtFormDate } from '@/format'
import DocumentFields from './DocumentFields.vue'
import DocumentSignatures from './DocumentSignatures.vue'
import ServiceRequestPrint from './ServiceRequestPrint.vue'
import type { Ticket,Approval } from '@/api'
import atsLogo from '@/assets/ats-logo.png'
import '@fontsource/noto-serif-thai/400.css'
import '@fontsource/noto-serif-thai/700.css'
const props=defineProps<{document:ITDocument;definition:DocumentDefinition;preview?:boolean;blank?:boolean;statusLabel?:string}>()
const printStatus=computed(()=>props.statusLabel??(props.definition.record&&props.document.status==='in_progress'?'':props.document.status!=='approved'?documentStates[props.document.status]:''))
const root=ref<HTMLElement|null>(null)
const servicePrint=ref<InstanceType<typeof ServiceRequestPrint>|null>(null)
const code=computed(()=>props.document.form_code)
// Keep the two original FM008 checkboxes; mixed requests select both.
const borrowHeaderDefinition=computed<DocumentDefinition>(()=>({...props.definition,fields:props.definition.fields.map(field=>field.key==='device_kind'?{...field,options:Object.fromEntries(Object.entries(field.options).filter(([key])=>key!=='mixed'))}:field)}))
const borrowHeaderDocument=computed<ITDocument>(()=>props.document.data.fields.device_kind==='mixed'?{...props.document,data:{...props.document.data,fields:{...props.document.data.fields,device_kind:['notebook','other']}}}:props.document)
const report=computed(()=>['FM-IT-001','FM-IT-002','FM-IT-004','FM-IT-005','FM-IT-006'].includes(code.value))
const requestKeys=computed(()=>props.definition.fields.filter(f=>f.phase==='request').map(f=>f.key))
const completionKeys=computed(()=>props.definition.fields.filter(f=>f.phase==='completion').map(f=>f.key))
const signatureKeys=computed(()=>props.definition.roles.map(r=>r.key))
const requestSigns=computed(()=>props.definition.roles.filter(r=>r.phase==='request').map(r=>r.key))
const completionSigns=computed(()=>props.definition.roles.filter(r=>r.phase==='completion').map(r=>r.key))
const f=(key:string)=>{const definition=props.definition.fields.find(f=>f.key===key);return definition?displayDocumentValue(definition,props.document.data.fields[key]):''}
const cell=(row:ITDocument['data']['rows'][number],key:string)=>{const c=props.definition.columns.find(c=>c.key===key);return c?displayDocumentValue(c,row[key]):''}
const months=['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.']
const monthColumns=computed(()=>props.definition.columns.filter(c=>/^m\d+$/.test(c.key)))
const planColumns=computed(()=>props.definition.columns.filter(c=>!/^m\d+$/.test(c.key)))
function weekText(value:unknown,week:number){
  if(typeof value!=='string'||!value)return ''
  const plan=value.match(/P\s*:\s*([1-4,\s]+)/i)?.[1]||''
  const actual=value.match(/A\s*:\s*([1-4,\s]+)/i)?.[1]||''
  if(!plan&&!actual)return week===1?value:''
  return [plan.match(/[1-4]/g)?.includes(String(week))?'P':'',actual.match(/[1-4]/g)?.includes(String(week))?'A':''].filter(Boolean).join('/')
}
const rows=computed<ITDocument['data']['rows']>(()=>props.document.data.rows.length?props.document.data.rows:Array.from({length:props.blank?(code.value.startsWith('FM-IT-007')?2:code.value==='FM-IT-008'?5:code.value==='FM-IT-002'?8:12):report.value?12:8},()=>({})))
const inspectionMark=(value:unknown)=>({normal:'/',abnormal:'X',fixed:'✓'} as Record<string,string>)[String(value)]||value
const roleSignature=(key:string)=>props.document.signatures.find(s=>s.role_key===key)
const assistant=computed(()=>{const s=roleSignature('md');return {name:s?.signer_name||null,signature:s?.signature_data||null,signedAt:s?.signed_at||null,rejected:s?.status==='rejected',note:s?.decision_note||null}})
const serviceTicket=computed<Ticket>(()=>{
  const d=props.document,head=roleSignature('head'),requester=roleSignature('requester'),it=roleSignature('it'),director=roleSignature('director')
  const approval=(s:typeof head,stage:'request'|'completion'):Approval|null=>s?{stage,attempt:1,work_revision:1,resolution_snapshot:f('result'),employee_id:s.employee_id||0,approver_name:s.signer_name,approver_email:s.signer_email||'',approver_company:d.company,approver_department:null,status:s.status==='approved'?'approved':s.status==='rejected'?'rejected':'pending',mail_status:'sent',mail_attempts:1,mail_sent_at:null,mail_error:null,expires_at:s.expires_at,signed_at:s.signed_at,signature_data:s.signature_data,decision_note:s.decision_note}:null
  return {id:d.id,ticket_no:d.document_no,type:'service',title:d.title,status:d.status==='approved'?'done':'new',priority:'normal',requester_name:f('requester'),requester_dept:f('department'),created_at:d.document_date+' 00:00:00',asset_id:null,asset_code:null,asset_model:null,assigned_to:null,assigned_name:it?.signer_name||null,requester_phone:null,requester_email:null,location:null,resolution:f('result')||null,fix_method:null,outsource_vendor:null,has_photo:false,started_at:null,finished_at:null,updated_at:d.updated_at,logs:[],details:{position:f('position'),actions:d.data.fields.actions||[],device_kind:d.data.fields.device_kind||'',os_target:f('os'),other_text:f('other')},description:f('description'),requester_signature:requester?.signature_data?{requester_name:requester.signer_name,signature_data:requester.signature_data,signed_at:requester.signed_at!}:null,approval:approval(head,'request'),it_signature:it?.signature_data?{admin_id:it.admin_id||0,signer_name:it.signer_name,signature_data:it.signature_data,signed_at:it.signed_at!,work_revision:1}:null,completion_required:true,completion_revision:1,completion_approval:approval(director,'completion'),completion_approval_current:true,default_completion_approver_id:null}
})
async function prepare(){
  if(code.value==='FM-IT-010'){await servicePrint.value?.prepare();return}
  await Promise.all([document.fonts.load('9pt "Noto Serif Thai"','แบบฟอร์มเอกสารฝ่ายเทคโนโลยีสารสนเทศ ABC 0123456789'),document.fonts.load('700 11pt "Noto Serif Thai"','แบบฟอร์มเอกสารฝ่ายเทคโนโลยีสารสนเทศ ABC')])
  await nextTick();await Promise.all(Array.from(root.value?.querySelectorAll('img')||[]).map(img=>img.decode().catch(()=>{})))
}
onMounted(prepare);defineExpose({prepare})
</script>
<template>
  <div ref="root" :class="['document-print',{preview,service:code==='FM-IT-010'},definition.orientation]">
    <ServiceRequestPrint v-if="code==='FM-IT-010'" ref="servicePrint" :ticket="serviceTicket" :assistant-signature="assistant" :document-title="document.title!==definition.title?document.title:undefined" :status-label="!blank&&document.status!=='approved'?documentStates[document.status]:undefined" />
    <article v-else :class="['doc-sheet',code==='FM-IT-003'||code==='FM-IT-007.1'?'compact':'']" :data-form-code="code">
      <header class="doc-header"><img :src="atsLogo" alt="Atthasan" /><div><h1>{{definition.title}}</h1><p v-if="code==='FM-IT-011'">แบบฟอร์มบันทึกการเข้าปฏิบัติงานด้าน IT (สำหรับบุคคลภายนอก)<br />(IT Visitor &amp; Contractor Log Form)</p><p>{{document.company}}</p></div></header>
      <div class="doc-meta"><span>เลขที่ {{blank?'........................................':document.document_no}}</span><span>วันที่ {{blank?'........../........../..........':fmtFormDate(document.document_date)}}</span><span v-if="!blank&&printStatus" class="draft-mark">{{printStatus}}</span></div>
      <p v-if="document.title!==definition.title" class="doc-subject">เรื่อง: {{document.title}}</p>
      <div class="doc-body">
        <template v-if="report">
          <DocumentFields :document="document" :definition="definition" :keys="requestKeys" :columns="3" />
          <p v-if="code==='FM-IT-002'" class="doc-legend">/ = ปกติ · X = ผิดปกติ · ✓ = แก้ไขแล้ว · ช่องว่าง = ยังไม่บันทึกผล</p>
          <table v-if="code==='FM-IT-006'" class="doc-table plan-table">
            <colgroup><col style="width:7mm"/><col style="width:65mm"/><col style="width:19mm"/><col style="width:19mm"/><col v-for="i in 48" :key="i" /></colgroup>
            <thead><tr><th rowspan="2">No.</th><th v-for="c in planColumns" :key="c.key" rowspan="2">{{c.label}}</th><th v-for="m in months" :key="m" colspan="4">{{m}}</th></tr><tr><template v-for="m in 12" :key="m"><th v-for="w in 4" :key="w" class="week-cell">{{w}}</th></template></tr></thead>
            <tbody><tr v-for="(row,i) in rows" :key="i"><td>{{i+1}}</td><td v-for="c in planColumns" :key="c.key">{{cell(row,c.key)}}</td><template v-for="c in monthColumns" :key="c.key"><td v-if="typeof row[c.key]==='string'&&row[c.key]&&!/\b[PA]\s*:/i.test(String(row[c.key]))" colspan="4">{{row[c.key]}}</td><template v-else><td v-for="w in 4" :key="w" class="week-cell">{{weekText(row[c.key],w)}}</td></template></template></tr></tbody>
          </table>
          <table v-else-if="code==='FM-IT-004'" class="doc-table"><thead><tr><th rowspan="2">วันที่</th><th rowspan="2" style="width:43%">รายละเอียดการบำรุงรักษา</th><th colspan="2">ประเภทการซ่อม</th><th rowspan="2">ระยะเวลา</th><th rowspan="2">ผู้รับผิดชอบ</th><th rowspan="2">หมายเหตุ</th></tr><tr><th>เสีย (BM)</th><th>ป้องกัน (PM)</th></tr></thead><tbody><tr v-for="(row,i) in rows" :key="i"><td>{{cell(row,'date')}}</td><td>{{cell(row,'detail')}}</td><td class="center">{{row.kind==='BM'?'✓':''}}</td><td class="center">{{row.kind==='PM'?'✓':''}}</td><td>{{cell(row,'duration')}}</td><td>{{cell(row,'responsible')}}</td><td>{{cell(row,'notes')}}</td></tr></tbody></table>
          <table v-else class="doc-table" :class="{'inspection-table':code==='FM-IT-002','backup-table':code==='FM-IT-005'}"><colgroup><col style="width:7mm"/><col v-for="c in definition.columns" :key="c.key" :style="/^m\d+$/.test(c.key)?'width:10mm':''" /></colgroup><thead><tr v-if="code==='FM-IT-002'"><th rowspan="2">ลำดับ</th><th rowspan="2">รายการตรวจสอบ</th><th rowspan="2">หมายเลขเครื่อง</th><th colspan="12">ผลตรวจสอบประจำปี {{f('year')}}</th><th rowspan="2">หมายเหตุ</th></tr><tr v-else-if="code==='FM-IT-005'"><th rowspan="2">ลำดับ</th><th rowspan="2">ว/ด/ป</th><th colspan="3">ข้อมูล Server {{f('server')}}</th><th colspan="2">แหล่งจัดเก็บข้อมูลสำรอง</th><th rowspan="2">ขนาด BACK UP</th><th rowspan="2">เวลาเริ่ม</th><th rowspan="2">เวลาเสร็จ</th><th rowspan="2">จัดทำ</th><th rowspan="2">รับทราบ</th><th rowspan="2">หมายเหตุ</th></tr><tr><th v-if="!['FM-IT-002','FM-IT-005'].includes(code)">ลำดับ</th><th v-for="c in definition.columns.filter(c=>code==='FM-IT-002'?/^m\d+$/.test(c.key):code==='FM-IT-005'?['dataset','size','source','device','folder'].includes(c.key):true)" :key="c.key">{{c.label}}</th></tr></thead><tbody><tr v-for="(row,i) in rows" :key="i"><td class="center">{{i+1}}</td><td v-for="c in definition.columns" :key="c.key" :class="{'center':/^m\d+$/.test(c.key)}">{{/^m\d+$/.test(c.key)?inspectionMark(row[c.key]):cell(row,c.key)}}</td></tr></tbody></table>
          <p v-if="code==='FM-IT-006'" class="doc-legend">P : Plan (แผนการทำ) · A : Actual (ทำจริง) · สัปดาห์ 1 วันที่ 1–7, 2 วันที่ 8–14, 3 วันที่ 15–21, 4 วันที่ 22–31</p>
          <DocumentSignatures :document="document" :definition="definition" :keys="signatureKeys" />
        </template>
        <template v-else-if="code==='FM-IT-003'">
          <h2>ผู้แจ้งซ่อม</h2><DocumentFields :document="document" :definition="definition" :keys="requestKeys" :columns="2" />
          <DocumentSignatures :document="document" :definition="definition" :keys="requestSigns" />
          <h2>รายละเอียดการแก้ปัญหา / ระยะเวลาปฏิบัติงาน</h2><DocumentFields :document="document" :definition="definition" :keys="completionKeys.filter(k=>!['acceptance','acceptance_note'].includes(k))" :columns="2" />
          <DocumentSignatures :document="document" :definition="definition" :keys="['it']" />
          <h2>ตรวจรับและส่งมอบงาน</h2><DocumentFields :document="document" :definition="definition" :keys="['acceptance','acceptance_note']" />
          <DocumentSignatures :document="document" :definition="definition" :keys="['acceptor','director','md']" />
        </template>
        <template v-else-if="code.startsWith('FM-IT-007')">
          <DocumentFields :document="document" :definition="definition" :keys="['requester','position','department','urgency','need_by','purpose']" :columns="2" />
          <h2>ประเภทอุปกรณ์ที่ต้องการ / รายละเอียดสเปกพื้นฐาน (Specification Details)</h2>
          <table class="doc-table"><thead><tr><th style="width:10mm">ลำดับ</th><th>อุปกรณ์</th><th>รายละเอียดสเปก</th><th style="width:15mm">จำนวน</th></tr></thead><tbody><tr v-for="(row,i) in rows" :key="i"><td>{{i+1}}</td><td>{{cell(row,'item')}}</td><td>{{cell(row,'spec')}}</td><td>{{cell(row,'quantity')}}</td></tr></tbody></table>
          <DocumentFields :document="document" :definition="definition" :keys="['cpu','ram','storage','display','other','software']" :columns="2" />
          <DocumentSignatures :document="document" :definition="definition" :keys="requestSigns" />
          <h2>ฝ่ายเทคโนโลยีสารสนเทศได้ตรวจสอบแล้ว จึงดำเนินการจัดซื้อดังนี้</h2>
          <DocumentFields :document="document" :definition="definition" :keys="completionKeys" :columns="2" />
          <DocumentSignatures :document="document" :definition="definition" :keys="completionSigns" />
        </template>
        <template v-else-if="code==='FM-IT-008'">
          <DocumentFields :document="borrowHeaderDocument" :definition="borrowHeaderDefinition" :keys="requestKeys" :columns="3" />
          <table class="doc-table borrow-table"><thead><tr><th colspan="7">ส่วนผู้ขอใช้</th><th colspan="5">ฝ่ายเทคโนโลยีสารสนเทศ / การคืน</th></tr><tr><th>ฝ่าย / แผนก</th><th>ผู้ขอใช้</th><th>อุปกรณ์ / วัตถุประสงค์</th><th>จำนวน</th><th>Serial No.</th><th>วันที่ใช้งาน</th><th>เจ้าหน้าที่ IT</th><th>ต้นสังกัด</th><th>วันที่เลิกใช้</th><th>ผู้คืน</th><th>เจ้าหน้าที่ IT</th><th>สภาพเมื่อคืน</th></tr></thead><tbody><tr v-for="(row,i) in rows" :key="i"><td>{{cell(row,'department')}}</td><td>{{cell(row,'user')}}</td><td><template v-if="cell(row,'device_kind')">{{cell(row,'device_kind')}}<br /></template>{{cell(row,'item')}}<br />{{cell(row,'purpose')}}</td><td>{{cell(row,'quantity')}}</td><td>{{cell(row,'serial')}}</td><td>{{cell(row,'date')||(!blank?f('start_date'):'')}}</td><td>{{cell(row,'issuer')}}</td><td>{{cell(row,'head')}}</td><td>{{cell(row,'returned_at')}}</td><td>{{cell(row,'returner')}}</td><td>{{cell(row,'receiver')}}</td><td>{{cell(row,'return_condition')}}</td></tr></tbody></table>
          <DocumentSignatures :document="document" :definition="definition" :keys="requestSigns" /><DocumentSignatures :document="document" :definition="definition" :keys="completionSigns" />
        </template>
        <template v-else-if="code==='FM-IT-009'">
          <h2>ส่วนที่ 1 : สำหรับผู้ขอใช้ E-mail</h2><DocumentFields :document="document" :definition="definition" :keys="requestKeys" />
          <DocumentSignatures :document="document" :definition="definition" :keys="requestSigns" />
          <p class="doc-legend">หมายเหตุ: รูปแบบ E-mail มาตรฐาน คือ ชื่อจริงภาษาอังกฤษ.อักษรตัวแรกของนามสกุล@atthasan.com</p>
          <h2>ส่วนที่ 2 : สำหรับฝ่ายเทคโนโลยีสารสนเทศ</h2><p>{{document.status==='approved'?'☑':'□'}} ดำเนินการสร้าง E-mail เรียบร้อยแล้ว</p><DocumentFields :document="document" :definition="definition" :keys="completionKeys" />
          <p class="doc-legend">ข้อมูลสำหรับเข้าใช้งานส่งให้พนักงานผ่านช่องทางส่วนบุคคล กรุณาเปลี่ยนรหัสผ่านเมื่อเข้าใช้งานครั้งแรก</p>
          <DocumentSignatures :document="document" :definition="definition" :keys="completionSigns" />
        </template>
        <template v-else-if="code==='FM-IT-010'">
          <DocumentFields :document="document" :definition="definition" :keys="['requester','position','department','device_kind']" :columns="2" />
          <h2 class="blue-tab">ต้องการให้ดำเนินการ</h2><DocumentFields :document="document" :definition="definition" :keys="['actions','os','other']" />
          <h2 class="blue-tab">รายละเอียดการร้องขอ</h2><DocumentFields :document="document" :definition="definition" :keys="['description']" />
          <DocumentSignatures :document="document" :definition="definition" :keys="requestSigns" />
          <h2 class="blue-tab">ผลการดำเนินการ</h2><DocumentFields :document="document" :definition="definition" :keys="['result']" />
          <DocumentSignatures :document="document" :definition="definition" :keys="['it','director']" /><DocumentSignatures :document="document" :definition="definition" :keys="['md']" />
        </template>
        <template v-else-if="code==='FM-IT-011'">
          <h2>ส่วนที่ 1 : ข้อมูลผู้มาติดต่อ (Visitor Information)</h2><DocumentFields :document="document" :definition="definition" :keys="['time_in','time_out','visitor','visitor_company','phone']" :columns="2" />
          <h2>ส่วนที่ 2 : รายละเอียดการปฏิบัติงาน (Work Details)</h2><DocumentFields :document="document" :definition="definition" :keys="['purpose','work_types','other']" />
          <h2>ส่วนที่ 3 : สำหรับเจ้าหน้าที่ฝ่ายเทคโนโลยีสารสนเทศ (IT Department Only)</h2><DocumentFields :document="document" :definition="definition" :keys="['result']" />
          <h2>ส่วนที่ 4 : การลงนามรับรองการปฏิบัติงาน (Signatures)</h2><DocumentSignatures :document="document" :definition="definition" :keys="['visitor','it']" /><DocumentSignatures :document="document" :definition="definition" :keys="['director']" />
        </template>
        <section v-if="document.signatures.some(s=>s.decision_note)" class="decision-notes"><h2>หมายเหตุการพิจารณา</h2><p v-for="s in document.signatures.filter(s=>s.decision_note)" :key="s.id"><b>{{s.role_label}} · {{s.signer_name}}:</b> {{s.decision_note}}</p></section>
      </div>
      <footer>{{code==='FM-IT-007.1'?'FM-IT-007':code}}, {{definition.rev}}<span>{{document.document_no}}</span></footer>
    </article>
  </div>
</template>
<style scoped>
.document-print{display:none;color:#000;font-family:'Noto Serif Thai',serif;font-size:9pt;line-height:1.45}
.document-print.preview{display:block;overflow-x:auto;margin-top:24px;padding:16px;background:#cbd5e1}
.doc-sheet{width:186mm;background:#fff;padding:0;margin:auto}.landscape .doc-sheet{width:276mm}
.document-print.preview.service :deep(.service-request-print){display:block;background:#fff;width:186.6mm;margin:auto}
.compact :deep(.paragraph-field){min-height:8mm}
.compact :deep(.doc-signature-group){margin:4mm 0}
.preview .doc-sheet{padding:10mm;width:206mm}.preview.landscape .doc-sheet{width:296mm}
.doc-header{display:flex;align-items:center;gap:5mm;margin-bottom:3mm;break-inside:avoid}.doc-header img{width:24mm;height:18mm;object-fit:contain}.doc-header>div{flex:1;text-align:center}.doc-header h1{font-size:12pt;font-weight:700;line-height:1.5;margin:0}.doc-header p{font-size:8.5pt;margin:1mm 0}
.doc-meta{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1mm 3mm;font-size:8pt;border-bottom:.3mm solid #000;padding-bottom:2mm;margin-bottom:3mm}.draft-mark{font-weight:bold}
.doc-subject{margin:2mm 0;overflow-wrap:anywhere;white-space:pre-wrap}
.doc-body{min-height:218mm}.landscape .doc-body{min-height:133mm}
.doc-body h2{font-size:9pt;font-weight:bold;background:#e2e8f0;border:.2mm solid #000;padding:1.2mm 2mm;margin:3mm 0 1mm;break-after:avoid}.doc-body h2.blue-tab{background:#9dc3e6;border:0;width:65mm}
.doc-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8pt;margin:2mm 0;line-height:1.4}.doc-table th,.doc-table td{border:.2mm solid #000;padding:1mm;vertical-align:top;overflow-wrap:anywhere;white-space:pre-wrap;min-height:5mm}.doc-table th{font-weight:bold;text-align:center;background:#f1f5f9}.doc-table td{height:5.5mm}.doc-table thead{display:table-header-group}.doc-table tr{break-inside:avoid}.center{text-align:center}.doc-legend{font-size:8pt;margin:2mm 0;white-space:pre-wrap;overflow-wrap:anywhere}.inspection-table{font-size:7.5pt}.plan-table{font-size:7pt}.plan-table .week-cell{padding:.5mm 0;text-align:center;font-size:6.5pt}.backup-table{font-size:7pt}.decision-notes p{white-space:pre-wrap;overflow-wrap:anywhere;margin:2mm 0}
footer{display:flex;justify-content:space-between;gap:3mm;border-top:.2mm solid #000;margin-top:4mm;padding-top:2mm;font-size:7pt;break-inside:avoid}
@page itDocumentPortrait{size:A4 portrait;margin:10mm 12mm 13mm}
@page itDocumentLandscape{size:A4 landscape;margin:10mm 10.5mm 13mm}
@media print{
 .document-print,.document-print.preview{display:block;overflow:visible;margin:0;padding:0;background:#fff}
 .portrait .doc-sheet{page:itDocumentPortrait;width:186mm;padding:0}.landscape .doc-sheet{page:itDocumentLandscape;width:276mm;padding:0}
 .doc-sheet{print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
