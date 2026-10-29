<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api'
import PublicShell from '@/components/PublicShell.vue'
import SignaturePad from '@/components/SignaturePad.vue'
import { fmtDate, fmtDateTime } from '@/format'
import { meta } from '@/store'

interface Document {
  type?: 'service'|'purchase'
  stage: string; resolution_snapshot: string | null
  approval_role?:string; purchase_fields?: Record<string,string|null>
  ticket_no: string; title: string; description: string; details: string
  requester_name: string; requester_dept: string; ticket_created_at: string
  approver_name: string; approver_company: string; approver_department: string | null
  expires_at: string; asset_code: string | null; asset_model: string | null
  saved_signature_data: string | null; saved_signature_hash: string | null
}
const route = useRoute()
const token = computed(() => route.hash.slice(1))
const doc = ref<Document | null>(null)
const isCompletion = computed(() => doc.value?.stage === 'completion')
const isPurchase = computed(()=>doc.value?.type==='purchase')
const isPurchaseSigning=computed(()=>isPurchase.value&&doc.value?.stage.startsWith('purchase_'))
const signerLabel = computed(() => doc.value?.approval_role||(isCompletion.value ? 'ผอ./ผจก. ฝ่ายสนับสนุนและติดตั้ง' : isPurchase.value?'ผู้มีอำนาจอนุมัติต้นสังกัด (หน./ผจก.)':'หัวหน้าฝ่าย / ผู้อนุมัติ'))
const loading = ref(true)
const error = ref('')
const formError = ref('')
const signature = ref('')
const signatureMode = ref<'saved' | 'draw'>('draw')
const hasSavedSignature = computed(() => !!doc.value?.saved_signature_data && !!doc.value?.saved_signature_hash)
const hasSignature = computed(() => signatureMode.value === 'saved' ? hasSavedSignature.value : !!signature.value)
const consent = ref(false)
const decision = ref('approved')
const decisionWording=computed(()=>isPurchaseSigning.value?(decision.value==='approved'?'รับรองเอกสารจัดซื้อฉบับที่แสดง':'ไม่รับรองเอกสารจัดซื้อฉบับที่แสดง'):isCompletion.value?(decision.value==='approved'?'รับรองผลการดำเนินการก่อนปิดงาน':'ไม่รับรองผลการดำเนินการ'):(decision.value==='approved'?'อนุมัติคำขอนี้':'ไม่อนุมัติคำขอนี้'))
const note = ref('')
const saving = ref(false)
const result = ref('')
const details = computed(() => { try { return JSON.parse(doc.value?.details || '{}') } catch { return {} } })
watch(token, async (value) => {
  loading.value = true
  error.value = ''
  doc.value = null
  result.value = ''
  consent.value = false
  signature.value = ''
  signatureMode.value = 'draw'
  try {
    doc.value = await api.post<Document>('/approval/view', { token: value })
    signatureMode.value = hasSavedSignature.value ? 'saved' : 'draw'
  }
  catch (e) { error.value = (e as Error).message }
  finally { loading.value = false }
}, { immediate: true })
async function submit() {
  if (saving.value) return
  formError.value = ''
  if (!hasSignature.value || !consent.value) { formError.value = 'กรุณาเลือกลายเซ็นหรือวาดใหม่ และยืนยันการลงนาม'; return }
  saving.value = true
  try {
    const res = await api.post<{status:string}>('/approval/decide', { token: token.value, decision: decision.value,
      signature_data: signatureMode.value === 'draw' ? signature.value : '', use_saved_signature: signatureMode.value === 'saved',
      saved_signature_hash: signatureMode.value === 'saved' ? doc.value?.saved_signature_hash : null,
      decision_note: note.value, consent: consent.value })
    result.value = res.status
    signature.value = ''
  } catch (e) { formError.value = (e as Error).message }
  finally { saving.value = false }
}
</script>

<template>
  <PublicShell>
    <h1 class="mb-2 text-xl font-bold">ลงนาม{{ signerLabel }}</h1>
    <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">{{isPurchase?'ใบขอซื้อครุภัณฑ์คอมพิวเตอร์ · FM-IT-007 Rev.02':'แบบฟอร์มร้องขอดำเนินการด้าน IT · FM-IT-010 Rev.01'}}</p>
    <p v-if="loading" class="card p-6">กำลังโหลดคำขอ...</p>
    <p v-else-if="error" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-5 text-red-700 dark:text-red-300">{{ error }}</p>
    <section v-else-if="result" class="card space-y-3 p-6 text-center">
      <h2 class="text-xl font-bold text-brand-700 dark:text-brand-300">บันทึกการลงนาม{{ isCompletion ? (result === 'approved' ? 'รับรองผล' : 'ไม่รับรองผล') : (result === 'approved' ? 'อนุมัติ' : 'ไม่อนุมัติ') }}แล้ว</h2>
      <p>เลขที่ {{ doc?.ticket_no }} · ระบบบันทึกลายเซ็นและวันเวลาลงนามเรียบร้อยแล้ว</p>
      <p v-if="isPurchaseSigning" class="text-sm text-slate-500 dark:text-slate-400">บันทึกการลงนามเอกสารจัดซื้อแล้ว สถานะงาน IT ไม่เปลี่ยน</p>
      <p class="text-sm text-slate-500 dark:text-slate-400">ลิงก์นี้ใช้ลงนามซ้ำไม่ได้ สามารถปิดหน้านี้ได้</p>
    </section>
    <template v-else-if="doc">
      <section class="card space-y-3 p-5">
        <p class="font-mono font-bold text-brand-700 dark:text-brand-300">{{ doc.ticket_no }}</p>
        <p><b>ผู้ร้องขอ:</b> {{ doc.requester_name }} · {{ doc.requester_dept }}</p>
        <p><b>วันที่:</b> {{ fmtDateTime(doc.ticket_created_at) }}</p>
        <p v-if="doc.asset_code"><b>อุปกรณ์:</b> {{ doc.asset_code }} {{ doc.asset_model }}</p>
        <p v-if="details.device_kind"><b>ประเภท:</b> {{ meta?.device_kinds[details.device_kind] || details.device_kind }}</p>
        <p v-if="details.position"><b>ตำแหน่งผู้ร้องขอ:</b> {{ details.position }}</p>
        <div v-if="isPurchase"><b>ประเภทอุปกรณ์ที่ขอซื้อ:</b><ul class="ml-5 list-disc"><li v-for="item in details.items" :key="item">{{meta?.purchase_items[item]||item}}</li></ul></div>
        <div v-else><b>ต้องการให้ดำเนินการ:</b><ul class="ml-5 list-disc"><li v-for="action in details.actions" :key="action">{{ meta?.service_actions[action] || action }}</li></ul></div>
        <template v-if="isPurchase">
          <p><b>ความต้องการ:</b> {{details.urgent?'ด่วน':'ไม่ด่วน'}}</p><p v-if="details.need_by"><b>ต้องการภายในวันที่:</b> {{fmtDate(details.need_by)}}</p>
          <p v-for="(label,key) in {spec_cpu:'CPU',spec_ram:'RAM',spec_storage:'SSD/HDD',spec_display:'ขนาดจอ',spec_other:'สเปกอื่นๆ'}" v-show="details[key]" :key="key" class="[overflow-wrap:anywhere]"><b>{{label}}:</b> {{details[key]}}</p>
        </template>
        <p v-if="details.os_target"><b>OS:</b> {{ details.os_target }}</p>
        <p v-if="details.other_text"><b>อื่นๆ:</b> {{ details.other_text }}</p>
        <div class="whitespace-pre-wrap rounded-lg bg-slate-50 dark:bg-slate-900 p-3 [overflow-wrap:anywhere]">{{ doc.description }}</div>
        <div v-if="isCompletion||isPurchaseSigning" class="rounded-xl bg-blue-50 dark:bg-blue-900/40 p-4 text-blue-950 dark:text-blue-300">
          <p class="font-semibold">{{isPurchaseSigning?'ข้อมูลจัดซื้อและผลที่ขอลงนาม':'ผลการดำเนินการที่ขอรับรองก่อนปิดงาน'}}</p>
          <template v-if="isPurchaseSigning"><p v-for="(label,key) in {vendor:'บริษัท / ร้าน',vendor_phone:'โทรศัพท์ผู้ขาย',quotation:'ใบเสนอราคา',quotation_date:'วันที่ใบเสนอราคา',amount:'รวมเป็นเงิน (บาท)',vat:'VAT'}" v-show="doc.purchase_fields?.[key]" :key="key" class="mt-2 [overflow-wrap:anywhere]"><b>{{label}}:</b> {{key==='vat'?(doc.purchase_fields?.[key]==='included'?'รวม VAT':'ไม่รวม VAT'):doc.purchase_fields?.[key]}}</p><p class="mt-2 text-sm">ลงนามเอกสารได้แม้ IT ปิดงานแล้ว การพิจารณานี้ไม่เปลี่ยนสถานะงาน</p></template>
          <p class="mt-2 whitespace-pre-wrap">{{ doc.resolution_snapshot }}</p>
        </div>
      </section>
      <form class="card mt-4 space-y-4 p-5" @submit.prevent="submit">
        <div><h2 class="font-semibold">{{ doc.approver_name }}</h2><p class="text-sm text-slate-500 dark:text-slate-400">{{ doc.approver_company }} · {{ doc.approver_department }}</p></div>
        <div><label for="decision" class="label">ผลการพิจารณา</label><select id="decision" v-model="decision" class="input"><option value="approved">{{ isPurchaseSigning?'ลงนาม / รับรองเอกสารจัดซื้อ':isCompletion ? 'รับรองผลการดำเนินการ' : 'อนุมัติ' }}</option><option value="rejected">{{ isPurchaseSigning?'ไม่รับรองเอกสารจัดซื้อ':isCompletion ? 'ไม่รับรองผลการดำเนินการ' : 'ไม่อนุมัติ' }}</option></select></div>
        <div><label for="note" class="label">{{ decision === 'rejected' ? 'เหตุผลที่ไม่อนุมัติ *' : 'หมายเหตุ (ถ้ามี)' }}</label><textarea id="note" v-model="note" class="input" rows="3" maxlength="2000" :required="decision === 'rejected'" /></div>
        <div class="space-y-3">
          <p class="label">ลายเซ็น *</p>
          <div v-if="hasSavedSignature" class="flex flex-wrap gap-3 text-sm">
            <label class="choice"><input v-model="signatureMode" type="radio" value="saved" /> ใช้ลายเซ็นจาก E-Procurement</label>
            <label class="choice"><input v-model="signatureMode" type="radio" value="draw" /> วาดลายเซ็นใหม่</label>
          </div>
          <div v-if="signatureMode === 'saved' && hasSavedSignature" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3">
            <img :src="doc.saved_signature_data!" alt="ลายเซ็นจาก E-Procurement" class="bg-white rounded mx-auto h-28 max-w-full object-contain" />
            <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">{{ doc.approver_name }} · ลายเซ็นนี้จะบันทึกกับคำขอเมื่อกดยืนยันการลงนาม</p>
          </div>
          <SignaturePad v-else v-model="signature" />
        </div>
        <label class="flex items-start gap-2 text-sm"><input v-model="consent" type="checkbox" class="mt-1" required /><span>ข้าพเจ้า {{ doc.approver_name }} ตรวจสอบ{{ isPurchaseSigning?'เอกสารจัดซื้อ':isCompletion ? 'ผลการดำเนินการ' : 'คำขอ' }}ข้างต้น และยืนยันใช้ลายเซ็นนี้เพื่อ{{decisionWording}}</span></label>
        <p class="text-xs text-slate-500 dark:text-slate-400">ลิงก์หมดอายุ {{ fmtDateTime(doc.expires_at) }} · กดยืนยันได้ครั้งเดียว</p>
        <p v-if="formError" role="alert" class="field-error">{{ formError }}</p>
        <button class="btn-primary w-full" :disabled="saving || !hasSignature || !consent">{{ saving ? 'กำลังบันทึก...' : 'ยืนยันการลงนาม' }}</button>
      </form>
    </template>
  </PublicShell>
</template>
