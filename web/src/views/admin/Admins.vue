<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, ApiError, type AdminRow } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { fmtDateTime } from '@/format'
import { auth, toast } from '@/store'

const admins = ref<AdminRow[]>([])
const editing = ref<AdminRow | 'new' | null>(null)
const f = reactive({ username: '', display_name: '', password: '', is_active: true })
const errors = ref<Record<string, string>>({})
const pw = reactive({ current_password: '', new_password: '' })
const pwErrors = ref<Record<string, string>>({})

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
            <tr><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>สถานะ</th><th>เข้าระบบล่าสุด</th><th /></tr>
          </thead>
          <tbody>
            <tr v-for="a in admins" :key="a.id">
              <td class="font-medium">{{ a.display_name }} <span v-if="a.id === auth.admin?.id" class="text-xs text-slate-400">(คุณ)</span></td>
              <td class="font-mono text-slate-600">{{ a.username }}</td>
              <td>
                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', a.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500']">
                  {{ a.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                </span>
              </td>
              <td class="whitespace-nowrap text-slate-600">{{ fmtDateTime(a.last_login_at) }}</td>
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
