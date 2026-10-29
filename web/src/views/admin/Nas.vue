<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { fmtDateTime } from '@/format'
import { toast } from '@/store'

type Mapping = {
  revision: string; unc: string; host: string; share: string; folder: string
  username: string; domain: string; verified_at: string
}
type Listing = { entries: { name: string; is_dir: boolean; size: number; modified_at: string }[]; truncated: boolean; checked_at: string }
type Status = { mapping: Mapping | null; runtime_ready: boolean }
const saved = ref<Mapping | null>(null)
const ready = ref(false)
const loading = ref(true)
const loadError = ref('')
const busy = ref<'test' | 'save' | 'disconnect' | null>(null)
const errors = ref<Record<string, string>>({})
const actionError = ref('')
const success = ref('')
const listing = ref<Listing | null>(null)
const showPassword = ref(false)
const confirmDisconnect = ref(false)
const formElement = ref<HTMLFormElement>()
const form = reactive({ unc: '', username: '', domain: '', password: '' })
const examplePath = '\\\\192.168.1.10\\Documents\\IT'
const disabled = computed(() => loading.value || !!busy.value || !!loadError.value)
const shareOf = (path: string) => path.trim().replaceAll('/', '\\').split('\\').slice(2, 4).join('\\').toLowerCase()
const canReusePassword = computed(() => !!saved.value && shareOf(form.unc) === shareOf(saved.value.unc)
  && form.username.trim() === saved.value.username && form.domain.trim() === saved.value.domain)
const changed = computed(() => !!saved.value && (form.unc.trim() !== saved.value.unc || form.username.trim() !== saved.value.username
  || form.domain.trim() !== saved.value.domain || form.password !== ''))

watch(form, () => { listing.value = null; actionError.value = ''; success.value = ''; errors.value = {} }, { flush: 'sync' })

function fill(mapping: Mapping | null) {
  Object.assign(form, { unc: mapping?.unc ?? '', username: mapping?.username ?? '', domain: mapping?.domain ?? '', password: '' })
  showPassword.value = false
}
async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const result = await api.get<Status>('/admin/nas')
    saved.value = result.mapping
    ready.value = result.runtime_ready
    fill(saved.value)
  } catch (e) { loadError.value = (e as Error).message }
  finally { loading.value = false }
}
async function connect(action: 'test' | 'save') {
  if (disabled.value || !ready.value || !formElement.value?.reportValidity()) return
  busy.value = action
  errors.value = {}
  actionError.value = ''
  success.value = ''
  listing.value = null
  try {
    const body = { ...form, revision: saved.value?.revision ?? null }
    if (action === 'save') {
      const result = await api.post<Status & { listing: Listing }>('/admin/nas', body)
      saved.value = result.mapping
      ready.value = result.runtime_ready
      fill(result.mapping)
      listing.value = result.listing
      success.value = 'บันทึกการเชื่อมต่อ NAS แล้ว'
      toast(success.value)
    } else {
      const result = await api.post<{ listing: Listing }>('/admin/nas/test', body)
      listing.value = result.listing
      success.value = 'ทดสอบสำเร็จ อ่านโฟลเดอร์ได้'
    }
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.errors
    actionError.value = (e as Error).message
  } finally { busy.value = null }
}
async function disconnect() {
  if (disabled.value || !saved.value) return
  busy.value = 'disconnect'
  actionError.value = ''
  try {
    await api.post('/admin/nas/disconnect', { revision: saved.value.revision })
    saved.value = null
    fill(null)
    listing.value = null
    success.value = 'ยกเลิกการเชื่อมต่อและลบบัญชีที่บันทึกไว้แล้ว'
    confirmDisconnect.value = false
    toast(success.value)
  } catch (e) { actionError.value = (e as Error).message; confirmDisconnect.value = false }
  finally { busy.value = null }
}
function sizeText(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}
load()
</script>

<template>
  <div class="mb-6 flex items-center gap-3">
    <div class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300"><Icon name="server" :size="25" /></div>
    <div><h1 class="text-2xl font-bold">Map NAS</h1><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">ตั้งค่าโฟลเดอร์เครือข่ายสำหรับ IT Hub</p></div>
  </div>

  <p v-if="loading" role="status" class="card p-6 text-sm text-slate-500 dark:text-slate-400">กำลังโหลดการตั้งค่า...</p>
  <div v-else-if="loadError" role="alert" class="card p-5 text-red-700 dark:text-red-300">{{ loadError }} <button class="ml-2 underline" @click="load">ลองใหม่</button></div>
  <template v-else>
    <p v-if="!ready" role="alert" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">เซิร์ฟเวอร์ยังไม่ได้ติดตั้งตัวเชื่อมต่อ NAS กรุณาแจ้งผู้ดูแลเซิร์ฟเวอร์ก่อนบันทึก</p>
    <div class="grid items-start gap-5 lg:grid-cols-3">
      <section class="card min-w-0 p-5 sm:p-6 lg:col-span-2">
        <h2 class="text-lg font-semibold">การเชื่อมต่อ NAS</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">วางพาธโฟลเดอร์จาก Windows Explorer แล้วกรอกบัญชีที่มีสิทธิ์เข้าถึง</p>
        <form ref="formElement" class="mt-5 space-y-5" @submit.prevent="connect('save')">
          <fieldset :disabled="disabled" class="min-w-0 space-y-5">
            <div>
              <label for="nas-unc" class="label">พาธ NAS <span class="text-red-500">*</span></label>
              <textarea id="nas-unc" v-model="form.unc" :placeholder="examplePath" required maxlength="2048" rows="3" spellcheck="false" autocomplete="off" :class="['input resize-y break-all font-mono text-sm', errors.unc && 'input-error']" :aria-invalid="!!errors.unc" aria-describedby="nas-unc-help nas-unc-error" />
              <p id="nas-unc-help" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">ใช้ IP ภายในเครือข่าย รองรับชื่อโฟลเดอร์ภาษาไทยและช่องว่าง</p>
              <p v-if="errors.unc" id="nas-unc-error" class="field-error">{{ errors.unc }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label for="nas-username" class="label">ชื่อผู้ใช้ NAS <span class="text-red-500">*</span></label>
                <input id="nas-username" v-model="form.username" required maxlength="128" autocomplete="off" spellcheck="false" :class="['input', errors.username && 'input-error']" :aria-invalid="!!errors.username" />
                <p v-if="errors.username" class="field-error">{{ errors.username }}</p>
              </div>
              <div>
                <label for="nas-domain" class="label">โดเมน <span class="font-normal text-slate-400">(ถ้ามี)</span></label>
                <input id="nas-domain" v-model="form.domain" maxlength="128" placeholder="เว้นว่างสำหรับบัญชีบน NAS" autocomplete="off" spellcheck="false" class="input" />
                <p v-if="errors.domain" class="field-error">{{ errors.domain }}</p>
              </div>
            </div>
            <div>
              <label for="nas-password" class="label">รหัสผ่าน NAS <span v-if="!canReusePassword" class="text-red-500">*</span></label>
              <div class="flex items-center gap-2">
                <input id="nas-password" v-model="form.password" :type="showPassword ? 'text' : 'password'" :required="!canReusePassword" maxlength="512" autocomplete="new-password" :placeholder="canReusePassword ? 'เว้นว่างเพื่อใช้รหัสผ่านเดิม' : 'กรอกรหัสผ่าน NAS'" :class="['input min-w-0', errors.password && 'input-error']" :aria-invalid="!!errors.password" aria-describedby="nas-password-help" />
                <button type="button" class="btn-secondary shrink-0 px-3" :aria-pressed="showPassword" aria-controls="nas-password" @click="showPassword = !showPassword">{{ showPassword ? 'ซ่อน' : 'แสดง' }}</button>
              </div>
              <p id="nas-password-help" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ canReusePassword ? 'มีรหัสผ่านที่บันทึกไว้แล้ว กรอกใหม่เมื่อต้องการเปลี่ยน' : 'ใช้บัญชีที่มีสิทธิ์อ่านโฟลเดอร์ที่ระบุ' }}</p>
              <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
            </div>
          </fieldset>
          <p v-if="changed" class="text-sm text-amber-700 dark:text-amber-300">มีการแก้ไขที่ยังไม่ได้บันทึก</p>
          <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5 dark:border-slate-700">
            <button type="submit" class="btn-primary" :disabled="disabled || !ready"><Icon name="check" :size="17" />{{ busy === 'save' ? 'กำลังเชื่อมต่อและบันทึก...' : 'บันทึกและเชื่อมต่อ' }}</button>
            <button type="button" class="btn-secondary" :disabled="disabled || !ready" @click="connect('test')"><Icon name="network" :size="17" />{{ busy === 'test' ? 'กำลังทดสอบ...' : 'ทดสอบการเชื่อมต่อ' }}</button>
            <button v-if="changed" type="button" class="btn-ghost" :disabled="disabled" @click="fill(saved)">คืนค่าที่บันทึกไว้</button>
          </div>
        </form>
      </section>

      <aside class="card min-w-0 p-5 sm:p-6">
        <h2 class="font-semibold">การตั้งค่าที่บันทึกไว้</h2>
        <span :class="['mt-3 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium', saved ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-400']"><span class="size-1.5 rounded-full bg-current" />{{ saved ? 'บันทึกแล้ว' : 'ยังไม่ได้ตั้งค่า' }}</span>
        <template v-if="saved">
          <dl class="mt-5 space-y-4 text-sm">
            <div><dt class="text-xs text-slate-500 dark:text-slate-400">เครื่อง / แชร์</dt><dd class="mt-1 break-all font-medium">{{ saved.host }} / {{ saved.share }}</dd></div>
            <div><dt class="text-xs text-slate-500 dark:text-slate-400">โฟลเดอร์</dt><dd class="mt-1 break-all">{{ saved.folder || 'โฟลเดอร์หลักของแชร์' }}</dd></div>
            <div><dt class="text-xs text-slate-500 dark:text-slate-400">บัญชี NAS</dt><dd class="mt-1 break-all">{{ saved.domain ? saved.domain + '\\' : '' }}{{ saved.username }}</dd></div>
            <div><dt class="text-xs text-slate-500 dark:text-slate-400">ตรวจสอบเมื่อบันทึกล่าสุด</dt><dd class="mt-1">{{ fmtDateTime(saved.verified_at) }}</dd></div>
          </dl>
          <button class="btn-ghost mt-5 w-full text-red-600 dark:text-red-400" :disabled="disabled" @click="confirmDisconnect = true"><Icon name="logout" :size="17" /> ยกเลิกการเชื่อมต่อ</button>
        </template>
        <p v-else class="mt-4 text-sm leading-relaxed text-slate-500 dark:text-slate-400">กรอกข้อมูลแล้วกดบันทึก ระบบจะทดสอบว่าอ่านโฟลเดอร์ได้ก่อนเก็บการเชื่อมต่อ</p>
        <p class="mt-5 border-t border-slate-100 pt-4 text-xs leading-relaxed text-slate-500 dark:border-slate-700 dark:text-slate-400">รหัสผ่านเก็บแบบเข้ารหัสและใช้ได้เฉพาะฝั่งเซิร์ฟเวอร์ หน้านี้เปิดให้เฉพาะ Admin</p>
      </aside>
    </div>

    <p v-if="busy" class="mt-5 text-sm text-slate-500 dark:text-slate-400" role="status">{{ busy === 'disconnect' ? 'กำลังยกเลิกการเชื่อมต่อ...' : 'กำลังตรวจสอบ NAS โปรดรอสักครู่...' }}</p>
    <p v-if="actionError" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300" role="alert">{{ actionError }}</p>
    <p v-if="success" class="mt-5 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200" role="status"><Icon name="check" :size="18" class="shrink-0" />{{ success }}</p>

    <section v-if="listing" class="card mt-5 overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-700"><h2 class="font-semibold">รายการในโฟลเดอร์ที่ทดสอบ</h2><p class="text-xs text-slate-500 dark:text-slate-400">{{ fmtDateTime(listing.checked_at) }}</p></div>
      <div v-if="!listing.entries.length" class="px-5 py-10 text-center"><Icon name="server" :size="30" class="mx-auto text-slate-400" /><p class="mt-3 font-medium">โฟลเดอร์นี้ว่าง</p><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">เชื่อมต่อสำเร็จ ยังไม่มีไฟล์หรือโฟลเดอร์ย่อย</p></div>
      <ul v-else class="divide-y divide-slate-100 dark:divide-slate-700">
        <li v-for="entry in listing.entries" :key="entry.name" class="flex items-start gap-3 px-5 py-3">
          <Icon :name="entry.is_dir ? 'server' : 'list'" :size="19" class="mt-0.5 shrink-0 text-slate-400" />
          <div class="min-w-0 flex-1"><p class="break-all text-sm font-medium">{{ entry.name }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ entry.is_dir ? 'โฟลเดอร์' : sizeText(entry.size) }} · {{ fmtDateTime(entry.modified_at) }}</p></div>
        </li>
      </ul>
      <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">แสดง {{ listing.entries.length }} รายการ{{ listing.truncated ? 'แรก (โฟลเดอร์นี้มีมากกว่า 100 รายการ)' : '' }}</p>
    </section>
  </template>

  <Modal v-if="confirmDisconnect" title="ยกเลิกการเชื่อมต่อ NAS" @close="!busy && (confirmDisconnect = false)">
    <p class="text-sm leading-relaxed">ต้องการลบการเชื่อมต่อและบัญชี NAS ที่บันทึกไว้หรือไม่? ไฟล์บน NAS ยังคงอยู่ครบ</p>
    <div class="mt-5 flex flex-wrap justify-end gap-3"><button class="btn-secondary" :disabled="!!busy" @click="confirmDisconnect = false">กลับ</button><button class="btn-danger" :disabled="!!busy" @click="disconnect">{{ busy ? 'กำลังยกเลิก...' : 'ยืนยันยกเลิกการเชื่อมต่อ' }}</button></div>
  </Modal>
</template>
