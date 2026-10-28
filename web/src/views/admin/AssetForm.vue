<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError, type Asset } from '@/api'
import Icon from '@/components/Icon.vue'
import { todayInput } from '@/format'
import { meta, toast } from '@/store'

const props = defineProps<{ id?: number }>()
const router = useRouter()

const f = reactive({
  asset_code: '',
  category_id: '' as string | number,
  company: 'ATS',
  brand_model: '',
  serial_no: '',
  hostname: '',
  holder_name: '',
  holder_nickname: '',
  department: '',
  location: '',
  status: 'active',
  registered_at: todayInput(),
  warranty_until: '',
  specs: '',
  notes: '',
})
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const loaded = ref(!props.id)

if (props.id) {
  api.get<Asset>(`/admin/assets/${props.id}`).then((a) => {
    for (const k of Object.keys(f) as (keyof typeof f)[]) {
      ;(f as Record<string, unknown>)[k] = (a as unknown as Record<string, unknown>)[k] ?? ''
    }
    loaded.value = true
  })
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const body = { ...f, category_id: f.category_id === '' ? null : Number(f.category_id) }
    const asset = props.id ? await api.put<Asset>(`/admin/assets/${props.id}`, body) : await api.post<Asset>('/admin/assets', body)
    toast('บันทึกแล้ว')
    router.replace({ name: 'asset-detail', params: { id: asset.id } })
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.errors
    toast((e as Error).message, 'error')
  } finally {
    saving.value = false
  }
}

const cls = (k: string) => ['input', errors.value[k] && 'input-error']
</script>

<template>
  <div class="flex items-center gap-3">
    <button class="btn-ghost -ml-2 p-2" aria-label="กลับ" @click="router.back()"><Icon name="back" /></button>
    <h1 class="text-2xl font-bold">{{ id ? 'แก้ไขอุปกรณ์' : 'เพิ่มอุปกรณ์' }}</h1>
  </div>

  <form v-if="loaded" class="card mt-6 max-w-3xl space-y-5 p-6" @submit.prevent="save">
    <div class="grid gap-4 sm:grid-cols-3">
      <div>
        <label class="label" for="asset_code">หมายเลขทะเบียน <span class="text-red-500">*</span></label>
        <input id="asset_code" v-model="f.asset_code" :class="[...cls('asset_code'), 'font-mono uppercase']" placeholder="MD-01-001" />
        <p v-if="errors.asset_code" class="field-error">{{ errors.asset_code }}</p>
      </div>
      <div>
        <label class="label" for="category_id">ประเภท</label>
        <select id="category_id" v-model="f.category_id" class="input">
          <option value="">-</option>
          <option v-for="c in meta!.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="company">บริษัท</label>
        <select id="company" v-model="f.company" class="input">
          <option>ATS</option>
          <option>INNO</option>
        </select>
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
      <div class="sm:col-span-2">
        <label class="label" for="brand_model">ยี่ห้อ / รุ่น <span class="text-red-500">*</span></label>
        <input id="brand_model" v-model="f.brand_model" :class="cls('brand_model')" />
        <p v-if="errors.brand_model" class="field-error">{{ errors.brand_model }}</p>
      </div>
      <div>
        <label class="label" for="status">สถานะ</label>
        <select id="status" v-model="f.status" class="input">
          <option v-for="(label, k) in meta!.asset_statuses" :key="k" :value="k">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="serial_no">Serial No.</label>
        <input id="serial_no" v-model="f.serial_no" class="input font-mono" />
      </div>
      <div>
        <label class="label" for="hostname">ชื่อเครื่อง (Hostname)</label>
        <input id="hostname" v-model="f.hostname" class="input font-mono" placeholder="ATSACC01" />
      </div>
    </div>

    <div class="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-3">
      <div>
        <label class="label" for="holder_name">ผู้ครอบครอง</label>
        <input id="holder_name" v-model="f.holder_name" class="input" />
      </div>
      <div>
        <label class="label" for="holder_nickname">ชื่อเล่น</label>
        <input id="holder_nickname" v-model="f.holder_nickname" class="input" />
        <p class="mt-1 text-xs text-slate-500">แสดงบนหน้าสแกน QR</p>
      </div>
      <div>
        <label class="label" for="department">หน่วยงานที่ใช้</label>
        <input id="department" v-model="f.department" class="input" />
      </div>
      <div class="sm:col-span-3">
        <label class="label" for="location">สถานที่ตั้ง</label>
        <input id="location" v-model="f.location" class="input" placeholder="เช่น อาคาร 1 ชั้น 2 ห้องบัญชี" />
      </div>
    </div>

    <div class="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
      <div>
        <label class="label" for="registered_at">วันที่ขึ้นทะเบียน</label>
        <input id="registered_at" v-model="f.registered_at" type="date" :class="cls('registered_at')" />
      </div>
      <div>
        <label class="label" for="warranty_until">หมดประกัน</label>
        <input id="warranty_until" v-model="f.warranty_until" type="date" :class="cls('warranty_until')" />
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="specs">สเปก</label>
        <textarea id="specs" v-model="f.specs" rows="3" class="input" placeholder="CPU / RAM / SSD / OS" />
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="notes">หมายเหตุ (เห็นเฉพาะ IT)</label>
        <textarea id="notes" v-model="f.notes" rows="2" class="input" />
      </div>
    </div>

    <div class="flex justify-end gap-2 border-t border-slate-100 pt-5">
      <button type="button" class="btn-secondary" @click="router.back()">ยกเลิก</button>
      <button class="btn-primary" :disabled="saving">{{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}</button>
    </div>
  </form>
  <div v-else class="card mt-6 h-96 max-w-3xl animate-pulse" />
</template>
