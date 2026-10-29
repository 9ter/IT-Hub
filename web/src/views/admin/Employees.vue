<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api, ApiError, type Employee } from '@/api'
import Modal from '@/components/Modal.vue'
import { toast } from '@/store'
import { fmtDateTime } from '@/format'

const employees = ref<Employee[]>([])
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const query = ref('')
const company = ref('')
const onlyApprovers = ref(false)
const editing = ref<Employee | 'new' | null>(null)
const errors = ref<Record<string,string>>({})
const initial = () => ({ full_name:'', email:'', phone:'', company:'', department:'', position:'', is_active:true, can_approve:false })
const form = reactive(initial())
const companyNames = (employee: Employee) => employee.companies || [employee.company]
const companies = computed(() => [...new Set(employees.value.flatMap(companyNames))])
const filtered = computed(() => employees.value.filter(e => (!company.value || companyNames(e).includes(company.value)) && (!onlyApprovers.value || e.can_approve) && `${e.full_name} ${e.email || ''} ${e.department || ''} ${e.position || ''}`.toLowerCase().includes(query.value.toLowerCase())))
const grouped = computed(() => editing.value !== null && editing.value !== 'new' && (editing.value.member_ids?.length || 1) > 1)
async function load() {
  loading.value = true
  error.value = ''
  try { employees.value = await api.get<Employee[]>('/admin/employees') }
  catch(e) { error.value = (e as Error).message }
  finally { loading.value = false }
}
load()
function open(employee: Employee | 'new') {
  editing.value = employee
  errors.value = {}
  Object.assign(form, employee === 'new' ? initial() : { ...employee, email:employee.email || '', phone:employee.phone || '', department:employee.department || '', position:employee.position || '', is_active:!!employee.is_active, can_approve:!!employee.can_approve })
}
async function save() {
  if (busy.value) return
  busy.value = true
  errors.value = {}
  try {
    employees.value = editing.value === 'new' ? await api.post<Employee[]>('/admin/employees', form) : await api.put<Employee[]>(`/admin/employees/${(editing.value as Employee).id}`, { ...form, apply_to_matching: grouped.value })
    editing.value = null
    toast('บันทึกข้อมูลพนักงานแล้ว')
  } catch(e) { if (e instanceof ApiError) errors.value = e.errors; toast((e as Error).message, 'error') }
  finally { busy.value = false }
}
async function sync() {
  if (busy.value) return
  busy.value = true
  try {
    const res = await api.post<{count:number;employees:Employee[]}>('/admin/employees/sync')
    employees.value = res.employees
    error.value = ''
    toast(`อัปเดตข้อมูลจาก E-Procurement ${res.count} รายการแล้ว`)
  } catch(e) { toast((e as Error).message, 'error') }
  finally { busy.value = false }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-bold">ข้อมูลพนักงาน</h1><div class="flex gap-2"><button class="btn-secondary" :disabled="busy" @click="sync">{{ busy ? 'กำลังดำเนินการ...' : 'ดึงข้อมูล E-Procurement' }}</button><button class="btn-primary" :disabled="busy" @click="open('new')">เพิ่มพนักงาน</button></div></div>
  <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">พนักงานคนเดียวกันที่มีข้อมูลตรงกันจะแสดงรวมเป็นรายการเดียว พร้อมบริษัทที่สังกัด ผู้ที่เปิดสิทธิ์ “ผู้อนุมัติ” และมี E-mail จะปรากฏในแบบฟอร์ม FM-IT-010</p>
  <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">การดึงข้อมูลจะอัปเดตชื่อ อีเมล บริษัท แผนก ตำแหน่ง และสถานะตามต้นทาง โดยคงสิทธิ์ผู้อนุมัติที่ตั้งไว้ใน IT Hub ส่วนพนักงานที่เพิ่มเองยังคงอยู่</p>
  <div class="my-5 flex flex-wrap gap-3"><input v-model="query" class="input max-w-sm" placeholder="ค้นหาชื่อ อีเมล แผนก ตำแหน่ง" aria-label="ค้นหาพนักงาน" /><select v-model="company" class="input max-w-sm" aria-label="กรองบริษัท"><option value="">ทุกบริษัท</option><option v-for="c in companies" :key="c">{{ c }}</option></select><label class="flex items-center gap-2 text-sm"><input v-model="onlyApprovers" type="checkbox" /> เฉพาะผู้อนุมัติ</label></div>
  <p v-if="error" class="mb-4 text-red-700 dark:text-red-300">{{ error }} <button class="underline" @click="load">ลองใหม่</button></p>
  <p v-if="loading">กำลังโหลด...</p>
  <section v-else class="card overflow-hidden"><div class="table-wrap"><table class="table"><thead><tr><th>ชื่อ-นามสกุล / ตำแหน่ง</th><th>บริษัท / แผนก</th><th>ติดต่อ</th><th>สถานะ</th><th>ที่มา</th><th /></tr></thead><tbody>
    <tr v-for="employee in filtered" :key="employee.id"><td><p class="font-medium">{{ employee.full_name }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ employee.position || '—' }}</p></td><td><p v-for="name in companyNames(employee)" :key="name">{{ name }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ employee.department || '—' }}</p></td><td><p>{{ employee.email || '—' }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ employee.phone }}</p></td><td><p :class="employee.is_active ? 'text-green-700 dark:text-green-300' : 'text-slate-400'">{{ employee.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</p><p v-if="employee.can_approve" class="text-xs text-brand-700 dark:text-brand-300">ผู้อนุมัติ</p></td><td class="text-xs text-slate-500 dark:text-slate-400">{{ employee.source_user_id ? 'E-Procurement' : 'เพิ่มเอง' }}<p v-if="employee.synced_at">{{ fmtDateTime(employee.synced_at) }}</p></td><td><button class="btn-secondary" :disabled="busy" @click="open(employee)">แก้ไข</button></td></tr>
    <tr v-if="!filtered.length"><td colspan="6" class="py-8 text-center text-slate-500 dark:text-slate-400">ไม่พบข้อมูลพนักงาน</td></tr>
  </tbody></table></div><p class="border-t border-slate-100 dark:border-slate-700 px-4 py-3 text-xs text-slate-500 dark:text-slate-400">แสดง {{ filtered.length }} จาก {{ employees.length }} รายการ</p></section>
  <Modal v-if="editing" :title="editing === 'new' ? 'เพิ่มพนักงาน' : 'แก้ไขพนักงาน'" @close="!busy && (editing = null)">
    <form class="space-y-4" @submit.prevent="save">
      <div v-if="grouped" class="rounded-lg bg-slate-50 dark:bg-slate-900 p-3 text-sm text-slate-600 dark:text-slate-300"><p>แก้ไขข้อมูลร่วมกันสำหรับทุกบริษัทที่แสดงในรายการนี้</p><p v-for="name in companyNames(editing as Employee)" :key="name">{{ name }}</p></div>
      <div v-for="field in (['full_name','company','department','position','email','phone'] as const)" v-show="field !== 'company' || !grouped" :key="field"><label :for="field" class="label">{{ {full_name:'ชื่อ-นามสกุล *',company:'บริษัท *',department:'ฝ่าย / แผนก',position:'ตำแหน่ง',email:'E-mail',phone:'เบอร์ติดต่อ'}[field] }}</label><input :id="field" v-model="form[field]" :type="field === 'email' ? 'email' : 'text'" :required="field === 'full_name' || field === 'company' || (field === 'email' && form.can_approve)" :class="['input', errors[field] && 'input-error']" :maxlength="field === 'phone' ? 50 : field === 'full_name' || field === 'email' ? 150 : 255" /><p v-if="errors[field]" class="field-error">{{ errors[field] }}</p></div>
      <label class="flex gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> ใช้งาน</label><label class="flex gap-2 text-sm"><input v-model="form.can_approve" type="checkbox" /> หัวหน้าฝ่าย / ผู้อนุมัติ (ต้องมี E-mail)</label>
      <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
    </form>
  </Modal>
</template>
