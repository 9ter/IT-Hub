<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { api, ApiError, type AdminRow } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import SignaturePad from '@/components/SignaturePad.vue'
import { fmtDateTime } from '@/format'
import { auth, toast } from '@/store'

const admins = ref<AdminRow[]>([])
const editing = ref<AdminRow | 'new' | null>(null)
const f = reactive({ username: '', display_name: '', password: '', is_active: true })
const errors = ref<Record<string, string>>({})
const pw = reactive({ current_password: '', new_password: '' })
const pwErrors = ref<Record<string, string>>({})
const signatureEditing = ref<AdminRow | null>(null)
const signatureMode = ref<'draw' | 'upload'>('draw')
const signatureData = ref('')
const signatureError = ref('')
const signatureBusy = ref(false)
const signatureLoading = ref(false)
let signatureReadVersion = 0
watch(signatureMode, () => { signatureReadVersion++; signatureData.value = ''; signatureError.value = ''; signatureLoading.value = false })
function openSignature(row: AdminRow) {
  signatureReadVersion++
  signatureEditing.value = row
  signatureMode.value = 'draw'
  signatureData.value = ''
  signatureError.value = ''
  signatureLoading.value = false
}
function closeSignature() {
  if (signatureBusy.value) return
  signatureReadVersion++
  signatureEditing.value = null
  signatureData.value = ''
}
async function uploadSignature(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  signatureData.value = ''
  signatureError.value = ''
  if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 8*1024*1024) {
    signatureError.value = 'เลือกไฟล์ PNG, JPG หรือ WebP ขนาดไม่เกิน 8 MB'
    return
  }
  const version = ++signatureReadVersion
  signatureLoading.value = true
  try {
    const bitmap = await createImageBitmap(file)
    const canvas = document.createElement('canvas')
    canvas.width = 600; canvas.height = 200
    const context = canvas.getContext('2d')!
    context.fillStyle = '#ffffff'; context.fillRect(0,0,600,200)
    const scale = Math.min(560/bitmap.width,160/bitmap.height)
    const width = bitmap.width*scale, height = bitmap.height*scale
    context.drawImage(bitmap,(600-width)/2,(200-height)/2,width,height)
    bitmap.close()
    const png = canvas.toDataURL('image/png')
    if (version !== signatureReadVersion) return
    if (png.length > 180000) throw new Error('รูปมีขนาดใหญ่เกินไป กรุณาครอปเฉพาะลายเซ็นแล้วเลือกใหม่')
    signatureData.value = png
  } catch(e) { if (version === signatureReadVersion) signatureError.value = e instanceof Error ? e.message : 'อ่านรูปไม่สำเร็จ กรุณาเลือกไฟล์ใหม่' }
  finally { if (version === signatureReadVersion) signatureLoading.value = false }
}
async function saveSignature(remove = false) {
  if (!signatureEditing.value || signatureBusy.value) return
  signatureBusy.value = true
  signatureError.value = ''
  try {
    const path = `/admin/admins/${signatureEditing.value.id}/signature`
    admins.value = remove ? await api.del<AdminRow[]>(path) : await api.put<AdminRow[]>(path,{signature_data:signatureData.value})
    signatureEditing.value = null
    signatureData.value = ''
    toast(remove ? 'ลบลายเซ็นประจำบัญชีแล้ว ลายเซ็นในใบงานเดิมยังคงอยู่' : 'บันทึกลายเซ็นสำหรับเอกสารเจ้าหน้าที่ IT แล้ว')
  } catch(e) { signatureError.value = (e as Error).message }
  finally { signatureBusy.value = false }
}

const load = async () => (admins.value = await api.get<AdminRow[]>('/admin/admins'))
load()

function open(row: AdminRow | 'new') {
  editing.value = row
  errors.value = {}
  Object.assign(f, row === 'new' ? { username: '', display_name: '', password: '', is_active: true } : { username: row.username, display_name: row.display_name, password: '', is_active: !!row.is_active })
}

async function save() {
  errors.value = {}
  try {
    admins.value =
      editing.value === 'new'
        ? await api.post<AdminRow[]>('/admin/admins', f)
        : await api.put<AdminRow[]>(`/admin/admins/${(editing.value as AdminRow).id}`, { display_name: f.display_name, is_active: f.is_active, password: f.password })
    editing.value = null
    toast('บันทึกแล้ว')
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.errors
    toast((e as Error).message, 'error')
  }
}

async function changePassword() {
  pwErrors.value = {}
  try {
    await api.post('/auth/password', pw)
    Object.assign(pw, { current_password: '', new_password: '' })
    toast('เปลี่ยนรหัสผ่านแล้ว')
  } catch (e) {
    if (e instanceof ApiError) pwErrors.value = e.errors
    toast((e as Error).message, 'error')
  }
}
</script>

<template>
  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold">ผู้ดูแลระบบ</h1>
    <button class="btn-primary" @click="open('new')"><Icon name="plus" :size="16" /> เพิ่มผู้ดูแล</button>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <section class="card overflow-hidden lg:col-span-2">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>สถานะ</th><th>ลายเซ็น</th><th>เข้าระบบล่าสุด</th><th /></tr>
          </thead>
          <tbody>
            <tr v-for="a in admins" :key="a.id">
              <td class="font-medium">{{ a.display_name }} <span v-if="a.id === auth.admin?.id" class="text-xs text-slate-400">(คุณ)</span></td>
              <td class="font-mono text-slate-600 dark:text-slate-300">{{ a.username }}</td>
              <td>
                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', a.is_active ? 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-900 text-slate-500 dark:text-slate-400']">
                  {{ a.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                </span>
              </td>
              <td><button class="btn-ghost px-2 py-1" :aria-label="`จัดการลายเซ็น ${a.display_name}`" @click="openSignature(a)"><img v-if="a.signature_data" :src="a.signature_data" :alt="`ลายเซ็น ${a.display_name}`" class="bg-white rounded h-10 w-24 object-contain" /><span v-else class="text-xs text-brand-700 dark:text-brand-300">เพิ่มลายเซ็น</span></button></td>
              <td class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ fmtDateTime(a.last_login_at) }}</td>
              <td><button class="btn-ghost p-2" :aria-label="`แก้ไข ${a.display_name}`" @click="open(a)"><Icon name="edit" :size="16" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card p-5">
      <h2 class="flex items-center gap-2 font-semibold"><Icon name="key" :size="18" /> เปลี่ยนรหัสผ่านของฉัน</h2>
      <form class="mt-4 space-y-3" @submit.prevent="changePassword">
        <div>
          <label class="label" for="current_password">รหัสผ่านปัจจุบัน</label>
          <input id="current_password" v-model="pw.current_password" type="password" autocomplete="current-password" :class="['input', pwErrors.current_password && 'input-error']" />
          <p v-if="pwErrors.current_password" class="field-error">{{ pwErrors.current_password }}</p>
        </div>
        <div>
          <label class="label" for="new_password">รหัสผ่านใหม่ (8 ตัวขึ้นไป)</label>
          <input id="new_password" v-model="pw.new_password" type="password" autocomplete="new-password" :class="['input', pwErrors.new_password && 'input-error']" />
          <p v-if="pwErrors.new_password" class="field-error">{{ pwErrors.new_password }}</p>
        </div>
        <button class="btn-secondary w-full">เปลี่ยนรหัสผ่าน</button>
      </form>
    </section>
  </div>

  <Modal v-if="signatureEditing" :title="`ลายเซ็น ${signatureEditing.display_name}`" wide @close="closeSignature">
    <form class="space-y-4" @submit.prevent="saveSignature()">
      <p class="text-sm text-slate-600 dark:text-slate-300">ใช้ในช่อง “เจ้าหน้าที่เทคโนโลยีสารสนเทศ” เมื่อเจ้าของบัญชีส่งผลดำเนินการหรือปิดงาน ชื่อที่แสดงของบัญชีจะใช้เป็นชื่อผู้ลงนามในเอกสาร</p>
      <div v-if="signatureEditing.signature_data" class="rounded-xl border border-slate-200 dark:border-slate-700 p-3"><p class="label">ลายเซ็นที่บันทึกไว้</p><img :src="signatureEditing.signature_data" alt="ลายเซ็นที่บันทึกไว้" class="bg-white rounded mx-auto h-24 max-w-full object-contain" /><p class="mt-2 text-xs text-slate-500 dark:text-slate-400">อัปเดต {{ fmtDateTime(signatureEditing.signature_updated_at) }}</p></div>
      <div class="flex flex-wrap gap-3 text-sm"><label class="choice"><input v-model="signatureMode" type="radio" value="draw" :disabled="signatureBusy" /> วาดลายเซ็น</label><label class="choice"><input v-model="signatureMode" type="radio" value="upload" :disabled="signatureBusy" /> อัปโหลดรูป</label></div>
      <SignaturePad v-if="signatureMode === 'draw'" v-model="signatureData" />
      <div v-else class="space-y-3"><label for="admin_signature_file" class="label">ไฟล์ PNG, JPG หรือ WebP (ไม่เกิน 8 MB)</label><input id="admin_signature_file" type="file" accept="image/png,image/jpeg,image/webp" class="input" :disabled="signatureBusy" @change="uploadSignature" /><p v-if="signatureLoading" class="text-sm text-slate-500 dark:text-slate-400">กำลังอ่านรูป...</p><img v-if="signatureData" :src="signatureData" alt="ตัวอย่างลายเซ็นใหม่" class="bg-white rounded mx-auto h-28 max-w-full object-contain" /></div>
      <p v-if="signatureError" role="alert" class="field-error">{{ signatureError }}</p>
      <div class="flex flex-wrap gap-2"><button class="btn-primary" :disabled="signatureBusy || signatureLoading || !signatureData">{{ signatureBusy ? 'กำลังบันทึก...' : 'บันทึกลายเซ็น' }}</button><button v-if="signatureEditing.signature_data" type="button" class="btn-danger" :disabled="signatureBusy" @click="saveSignature(true)">ลบลายเซ็นประจำบัญชี</button></div>
    </form>
  </Modal>

  <Modal v-if="editing" :title="editing === 'new' ? 'เพิ่มผู้ดูแล' : 'แก้ไขผู้ดูแล'" @close="editing = null">
    <form class="space-y-4" @submit.prevent="save">
      <div>
        <label class="label" for="a_username">ชื่อผู้ใช้</label>
        <input id="a_username" v-model="f.username" :disabled="editing !== 'new'" :class="['input font-mono', errors.username && 'input-error']" autocomplete="off" />
        <p v-if="errors.username" class="field-error">{{ errors.username }}</p>
      </div>
      <div>
        <label class="label" for="a_display">ชื่อที่แสดง</label>
        <input id="a_display" v-model="f.display_name" :class="['input', errors.display_name && 'input-error']" />
        <p v-if="errors.display_name" class="field-error">{{ errors.display_name }}</p>
      </div>
      <div>
        <label class="label" for="a_password">{{ editing === 'new' ? 'รหัสผ่าน' : 'ตั้งรหัสผ่านใหม่ (เว้นว่าง = ไม่เปลี่ยน)' }}</label>
        <input id="a_password" v-model="f.password" type="password" autocomplete="new-password" :class="['input', errors.password && 'input-error']" />
        <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
      </div>
      <label v-if="editing !== 'new'" class="flex items-center gap-2 text-sm">
        <input v-model="f.is_active" type="checkbox" class="size-4 accent-brand-700" /> เปิดใช้งาน
      </label>
      <p v-if="errors.is_active" class="field-error">{{ errors.is_active }}</p>
      <button class="btn-primary w-full">บันทึก</button>
    </form>
  </Modal>
</template>
