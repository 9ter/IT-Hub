<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError, type Asset } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { fmtDate, todayInput } from '@/format'
import { assetUrl, qrDataUrl } from '@/qr'
import { meta, toast } from '@/store'

const props = defineProps<{ id: number }>()
const router = useRouter()

const asset = ref<Asset | null>(null)
const qr = ref('')
const error = ref('')
const showAdd = ref(false)
const m = reactive({ kind: 'PM', performed_on: todayInput(), detail: '', duration: '', performed_by: '' })
const mErrors = ref<Record<string, string>>({})

watch(
  () => props.id,
  async (id) => {
    try {
      asset.value = await api.get<Asset>(`/admin/assets/${id}`)
      qr.value = await qrDataUrl(assetUrl(asset.value.asset_code), 480)
    } catch (e) {
      error.value = (e as Error).message
    }
  },
  { immediate: true },
)

async function addMaintenance() {
  mErrors.value = {}
  try {
    asset.value = await api.post<Asset>(`/admin/assets/${props.id}/maintenance`, m)
    showAdd.value = false
    Object.assign(m, { detail: '', duration: '', performed_by: '' })
    toast('บันทึกประวัติแล้ว')
  } catch (e) {
    if (e instanceof ApiError) mErrors.value = e.errors
    toast((e as Error).message, 'error')
  }
}

async function deleteMaintenance(recordId: number) {
  if (!confirm('ลบประวัตินี้?')) return
  asset.value = await api.del<Asset>(`/admin/assets/${props.id}/maintenance/${recordId}`)
}

async function deleteAsset() {
  if (!confirm(`ลบอุปกรณ์ ${asset.value?.asset_code}? (ลบได้เฉพาะเครื่องที่ยังไม่มีประวัติคำขอ)`)) return
  try {
    await api.del(`/admin/assets/${props.id}`)
    toast('ลบแล้ว')
    router.replace({ name: 'assets' })
  } catch (e) {
    toast((e as Error).message, 'error')
  }
}

const statusTone: Record<string, string> = {
  active: 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300',
  repair: 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  spare: 'bg-slate-100 dark:bg-slate-900 text-slate-600 dark:text-slate-300',
  retired: 'bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400',
}
</script>

<template>
  <p v-if="error" class="rounded-xl bg-red-50 dark:bg-red-900/40 p-4 text-red-700 dark:text-red-300">{{ error }}</p>
  <div v-else-if="!asset" class="card h-64 animate-pulse" />

  <template v-else>
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink :to="{ name: 'assets' }" class="btn-ghost -ml-2 p-2" aria-label="กลับ"><Icon name="back" /></RouterLink>
      <div class="min-w-0 flex-1">
        <h1 class="font-mono text-2xl font-bold">{{ asset.asset_code }}</h1>
        <p class="text-slate-500 dark:text-slate-400">{{ asset.brand_model }}</p>
      </div>
      <span :class="['rounded-full px-2.5 py-1 text-xs font-semibold', statusTone[asset.status]]">{{ meta?.asset_statuses[asset.status] }}</span>
      <RouterLink :to="{ name: 'asset-edit', params: { id: asset.id } }" class="btn-secondary"><Icon name="edit" :size="16" /> แก้ไข</RouterLink>
      <RouterLink :to="{name:'document-new',query:{form:'FM-IT-004',asset:String(asset.id)}}" class="btn-secondary">จัดทำ / พิมพ์ FM-IT-004</RouterLink>
      <button class="btn-ghost p-2.5 text-red-600 dark:text-red-400" title="ลบ" aria-label="ลบอุปกรณ์" @click="deleteAsset"><Icon name="trash" :size="18" /></button>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <section class="card p-5 lg:col-span-2">
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
          <div><dt class="text-slate-500 dark:text-slate-400">ประเภท</dt><dd class="font-medium">{{ asset.category_name ?? '-' }} · {{ asset.company }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">Serial No.</dt><dd class="font-mono font-medium">{{ asset.serial_no ?? '-' }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">ชื่อเครื่อง</dt><dd class="font-mono font-medium">{{ asset.hostname ?? '-' }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">ผู้ครอบครอง</dt><dd class="font-medium">{{ asset.holder_name ?? '-' }} <span v-if="asset.holder_nickname" class="text-slate-500 dark:text-slate-400">({{ asset.holder_nickname }})</span></dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">หน่วยงาน</dt><dd class="font-medium">{{ asset.department ?? '-' }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">สถานที่</dt><dd class="font-medium">{{ asset.location ?? '-' }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">วันที่ขึ้นทะเบียน</dt><dd class="font-medium">{{ fmtDate(asset.registered_at) }}</dd></div>
          <div><dt class="text-slate-500 dark:text-slate-400">หมดประกัน</dt><dd class="font-medium">{{ fmtDate(asset.warranty_until) }}</dd></div>
          <div v-if="asset.specs" class="sm:col-span-2"><dt class="text-slate-500 dark:text-slate-400">สเปก</dt><dd class="font-medium whitespace-pre-line">{{ asset.specs }}</dd></div>
          <div v-if="asset.notes" class="sm:col-span-2"><dt class="text-slate-500 dark:text-slate-400">หมายเหตุ</dt><dd class="font-medium whitespace-pre-line">{{ asset.notes }}</dd></div>
        </dl>
      </section>

      <section class="card flex flex-col items-center p-5 text-center">
        <img v-if="qr" :src="qr" :alt="`QR ${asset.asset_code}`" class="w-44" />
        <p class="mt-2 font-mono font-bold">{{ asset.asset_code }}</p>
        <a :href="assetUrl(asset.asset_code)" target="_blank" class="mt-1 text-xs break-all text-brand-700 dark:text-brand-300">{{ assetUrl(asset.asset_code) }}</a>
        <div class="mt-4 flex gap-2">
          <a :href="qr" :download="`QR-${asset.asset_code}.png`" class="btn-secondary px-3 py-2 text-xs">ดาวน์โหลด PNG</a>
          <RouterLink :to="{ name: 'qr-print', query: { ids: String(asset.id) } }" target="_blank" class="btn-secondary px-3 py-2 text-xs">พิมพ์สติกเกอร์</RouterLink>
        </div>
      </section>
    </div>

    <section class="card mt-6">
      <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 px-5 py-4">
        <h2 class="font-semibold">ประวัติบำรุงรักษา <span class="text-sm font-normal text-slate-400">FM-IT-004</span></h2>
        <button class="btn-secondary px-3 py-1.5 text-sm" @click="showAdd = true"><Icon name="plus" :size="14" /> เพิ่มประวัติ</button>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>วันที่</th><th>ประเภท</th><th>รายละเอียด</th><th>ระยะเวลา</th><th>ผู้ดำเนินการ</th><th /></tr>
          </thead>
          <tbody>
            <tr v-for="r in asset.maintenance" :key="r.id">
              <td class="whitespace-nowrap">{{ fmtDate(r.performed_on) }}</td>
              <td><span :class="['rounded-md px-2 py-0.5 text-xs font-semibold', r.kind === 'BM' ? 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300' : 'bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300']">{{ r.kind }}</span></td>
              <td class="min-w-64 whitespace-pre-line">
                {{ r.detail }}
                <RouterLink v-if="r.ticket_id" :to="{ name: 'ticket', params: { id: r.ticket_id } }" class="block font-mono text-xs text-brand-700 dark:text-brand-300">{{ r.ticket_no }}</RouterLink>
              </td>
              <td class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ r.duration ?? '-' }}</td>
              <td class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ r.performed_by ?? '-' }}</td>
              <td>
                <button v-if="!r.ticket_id" class="text-slate-400 hover:text-red-600 dark:hover:text-red-400" aria-label="ลบประวัติ" @click="deleteMaintenance(r.id)"><Icon name="trash" :size="16" /></button>
              </td>
            </tr>
            <tr v-if="!asset.maintenance.length"><td colspan="6" class="py-8 text-center text-slate-400">ยังไม่มีประวัติ</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card mt-6">
      <h2 class="border-b border-slate-100 dark:border-slate-700 px-5 py-4 font-semibold">คำขอที่เกี่ยวข้อง</h2>
      <RouterLink
        v-for="t in asset.tickets"
        :key="t.id"
        :to="{ name: 'ticket', params: { id: t.id } }"
        class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-700 px-5 py-3 text-sm last:border-0 hover:bg-slate-50 dark:hover:bg-slate-900"
      >
        <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ t.ticket_no }}</span>
        <span class="min-w-0 flex-1 truncate">{{ t.title }}</span>
        <span class="hidden text-xs text-slate-500 dark:text-slate-400 sm:inline">{{ fmtDate(t.created_at) }}</span>
        <StatusBadge :status="t.status" />
      </RouterLink>
      <p v-if="!asset.tickets.length" class="py-8 text-center text-sm text-slate-400">ยังไม่มีคำขอ</p>
    </section>

    <Modal v-if="showAdd" title="เพิ่มประวัติบำรุงรักษา" @close="showAdd = false">
      <form class="space-y-4" @submit.prevent="addMaintenance">
        <div class="grid grid-cols-2 gap-2">
          <label class="choice text-sm"><input v-model="m.kind" type="radio" value="PM" /> PM ป้องกัน</label>
          <label class="choice text-sm"><input v-model="m.kind" type="radio" value="BM" /> BM ซ่อมเมื่อเสีย</label>
        </div>
        <div>
          <label class="label" for="performed_on">วันที่</label>
          <input id="performed_on" v-model="m.performed_on" type="date" :class="['input', mErrors.performed_on && 'input-error']" />
        </div>
        <div>
          <label class="label" for="detail">รายละเอียด</label>
          <textarea id="detail" v-model="m.detail" rows="3" :class="['input', mErrors.detail && 'input-error']" placeholder="เช่น ทำความสะอาด เป่าฝุ่น อัปเดต Windows" />
          <p v-if="mErrors.detail" class="field-error">{{ mErrors.detail }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="duration">ระยะเวลา</label><input id="duration" v-model="m.duration" class="input" placeholder="30 นาที" /></div>
          <div><label class="label" for="performed_by">ผู้ดำเนินการ</label><input id="performed_by" v-model="m.performed_by" class="input" placeholder="(ตัวเอง)" /></div>
        </div>
        <button class="btn-primary w-full">บันทึก</button>
      </form>
    </Modal>
  </template>
</template>
