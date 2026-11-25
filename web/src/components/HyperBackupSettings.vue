<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import { fmtDateTime } from '@/format'
import type { BackupRow, HyperBackupConnection, HyperBackupTask } from '@/backups'
import Modal from './Modal.vue'

const props = defineProps<{ connection: HyperBackupConnection | null; last: BackupRow | null }>()
const emit = defineEmits<{ close: []; saved: [connection: HyperBackupConnection | null] }>()
const c = props.connection
const form = reactive({
  host: c?.host ?? '', port: String(c?.port ?? 5001), username: c?.username ?? '', password: '',
  task_id: c?.task_id ?? 0,
  defaults: {
    server: c?.defaults.server ?? props.last?.server ?? 'SERVER', dataset: c?.defaults.dataset ?? props.last?.dataset ?? '',
    source: c?.defaults.source ?? props.last?.source ?? '', device: c?.defaults.device ?? props.last?.device ?? '', folder: c?.defaults.folder ?? props.last?.folder ?? '',
  },
})
const tested = ref<{ fingerprint: string; tasks: HyperBackupTask[] } | null>(null)
const busy = ref(false), error = ref(''), errors = ref<Record<string, string>>({})
const reuse = computed(() => !!c && form.host === c.host && Number(form.port) === c.port && form.username === c.username)
const body = () => ({ ...form, port: Number(form.port), revision: c?.revision ?? null })
function changed() { tested.value = null }
async function run(fn: () => Promise<void>) {
  if (busy.value) return
  busy.value = true; error.value = ''; errors.value = {}
  try { await fn() } catch (e) { const err = e as ApiError; errors.value = err.errors ?? {}; error.value = err.message }
  finally { busy.value = false }
}
const test = () => run(async () => {
  tested.value = await api.post('/admin/backups/hyperbackup/test', body())
  if (!tested.value!.tasks.some(t => t.task_id === form.task_id)) form.task_id = tested.value!.tasks[0]?.task_id ?? 0
})
const save = () => run(async () => {
  const r = await api.post<{ connection: HyperBackupConnection }>('/admin/backups/hyperbackup', { ...body(), fingerprint: tested.value?.fingerprint })
  form.password = ''
  emit('saved', r.connection)
})
const disconnect = () => {
  if (!window.confirm('ยกเลิกการเชื่อมต่อ Hyper Backup? บันทึก Backup ที่มีอยู่ไม่ถูกลบ')) return
  run(async () => { await api.post('/admin/backups/hyperbackup/disconnect', { revision: c?.revision }); emit('saved', null) })
}
const close = () => { if (!busy.value) emit('close') }
const defaultFields = [
  { key: 'server', label: 'ชื่อ Server' }, { key: 'dataset', label: 'ข้อมูลที่ BACK UP *' }, { key: 'source', label: 'แหล่งจัดเก็บข้อมูลหลัก' },
  { key: 'device', label: 'อุปกรณ์สำรอง' }, { key: 'folder', label: 'ชื่อ Folder / File' },
] as const
</script>

<template>
  <Modal title="เชื่อมต่อ Synology Hyper Backup" wide @close="close">
    <form class="space-y-4" novalidate @submit.prevent="test">
      <p class="text-sm text-slate-600 dark:text-slate-300">ใช้บัญชี DSM สำหรับ IT Hub โดยเฉพาะ (อยู่ในกลุ่ม administrators และไม่เปิด 2FA) ระบบอ่านผล Hyper Backup อย่างเดียว ไม่สั่งงานหรือแก้ไขอะไรบน NAS รหัสผ่านเก็บแบบเข้ารหัสบนเซิร์ฟเวอร์ ไม่แสดงกลับมาที่หน้านี้</p>
      <div class="grid gap-3 sm:grid-cols-3">
        <label class="block sm:col-span-2"><span class="label">IP ของ NAS (DSM)</span><input v-model="form.host" class="input" inputmode="decimal" placeholder="เช่น 192.168.1.10" @input="changed" /><span v-if="errors.host" class="field-error">{{ errors.host }}</span></label>
        <label class="block"><span class="label">พอร์ต HTTPS</span><input v-model="form.port" class="input" inputmode="numeric" @input="changed" /><span v-if="errors.port" class="field-error">{{ errors.port }}</span></label>
        <label class="block"><span class="label">ชื่อผู้ใช้ DSM</span><input v-model="form.username" class="input" autocomplete="off" @input="changed" /><span v-if="errors.username" class="field-error">{{ errors.username }}</span></label>
        <label class="block sm:col-span-2"><span class="label">รหัสผ่าน DSM</span><input v-model="form.password" type="password" class="input" autocomplete="new-password" :placeholder="reuse ? 'เว้นว่างเพื่อใช้รหัสผ่านที่บันทึกไว้' : ''" @input="changed" /><span v-if="errors.password" class="field-error">{{ errors.password }}</span></label>
      </div>
      <div class="flex justify-end"><button type="submit" class="btn-secondary" :disabled="busy">{{ busy && !tested ? 'กำลังทดสอบ...' : 'ทดสอบการเชื่อมต่อ' }}</button></div>
      <p v-if="error" role="alert" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>

      <template v-if="tested">
        <div class="rounded-xl bg-green-50 dark:bg-green-900/40 p-3 text-sm text-green-800 dark:text-green-300" role="status">
          เข้าสู่ระบบ DSM ได้ · พบงาน Hyper Backup {{ tested.tasks.length }} งาน
          <p class="mt-1 break-all text-xs">ลายนิ้วมือใบรับรอง (SHA-256): <span class="font-mono">{{ tested.fingerprint }}</span><br />ระบบจะจำใบรับรองนี้ ถ้า NAS เปลี่ยนใบรับรองต้องบันทึกการเชื่อมต่อใหม่</p>
        </div>
        <label class="block"><span class="label">งาน Hyper Backup ที่บันทึกลงรายงาน</span>
          <select v-model.number="form.task_id" class="input"><option v-for="t in tested.tasks" :key="t.task_id" :value="t.task_id">{{ t.name }}{{ t.last_bkp_time ? ` · ล่าสุด ${t.last_bkp_time}` : '' }}{{ t.last_bkp_result ? ` (${t.last_bkp_result})` : '' }}</option></select>
          <span v-if="errors.task_id" class="field-error">{{ errors.task_id }}</span></label>
        <fieldset class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
          <legend class="px-1 text-sm font-medium">ข้อมูลที่ใส่ในแถวรายงาน</legend>
          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="f in defaultFields" :key="f.key" class="block"><span class="label">{{ f.label }}</span><input v-model="form.defaults[f.key]" class="input" /><span v-if="errors[f.key]" class="field-error">{{ errors[f.key] }}</span></label>
          </div>
          <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">จัดทำ = “ระบบอัตโนมัติ (Hyper Backup)” · รับทราบ เว้นว่างให้ผู้ตรวจกรอก</p>
        </fieldset>
      </template>

      <p v-if="c?.verified_at" class="text-xs text-slate-500 dark:text-slate-400">บันทึกล่าสุด {{ fmtDateTime(c.verified_at) }} · งาน {{ c.task_name }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button v-if="c" type="button" class="btn-ghost text-red-600 dark:text-red-400" :disabled="busy" @click="disconnect">ยกเลิกการเชื่อมต่อ</button>
        <span class="flex-1" />
        <button type="button" class="btn-secondary" :disabled="busy" @click="close">ปิด</button>
        <button type="button" class="btn-primary" :disabled="busy || !tested || !form.task_id" :title="tested ? '' : 'ทดสอบการเชื่อมต่อก่อน'" @click="save">{{ busy && tested ? 'กำลังบันทึก...' : 'บันทึกการเชื่อมต่อ' }}</button>
      </div>
    </form>
  </Modal>
</template>
