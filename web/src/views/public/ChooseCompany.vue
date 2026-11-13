<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import itHubLogo from '@/assets/ithub-logo.png'
import { companies, companyLogoUrl, companySkipped, loadCompanies, selectCompany, selectedCompany, type Company } from '@/companies'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const error = ref('')

/** กลับไปหน้าที่ผู้ใช้ตั้งใจเปิด (เฉพาะ path ภายในเว็บนี้) */
function nextTarget(): string {
  const next = typeof route.query.next === 'string' ? route.query.next : ''
  return next.startsWith('/') && !next.startsWith('//') && !next.startsWith('/choose-company') ? next : '/'
}

async function load() {
  loading.value = true
  error.value = ''
  try { await loadCompanies() }
  catch (e) { error.value = (e as Error).message || 'โหลดรายชื่อบริษัทไม่สำเร็จ' }
  finally { loading.value = false }
}

function choose(c: Company) {
  selectCompany(c)
  router.replace(nextTarget())
}

function skip() {
  companySkipped.value = true
  router.replace(nextTarget())
}

onMounted(load)
</script>

<template>
  <div class="relative flex min-h-dvh items-center justify-center bg-slate-100 px-4 py-10 dark:bg-slate-950">
    <div class="absolute top-3 right-3"><ThemeToggle /></div>
    <main class="w-full max-w-md rounded-3xl border-t-4 border-brand-400 bg-white px-6 py-10 text-center shadow-xl sm:px-10 dark:bg-slate-900 dark:shadow-black/40">
      <img :src="itHubLogo" alt="IT Hub" width="1721" height="914" class="mx-auto h-16 w-auto object-contain" />
      <h1 class="mt-5 text-2xl font-bold text-slate-900 dark:text-slate-50">เลือกบริษัท</h1>
      <p class="mt-1 text-xs font-semibold tracking-[0.2em] text-amber-600 dark:text-amber-400">IT HUB</p>
      <p class="mt-4 text-sm leading-relaxed text-slate-500 dark:text-slate-400">
        เลือกบริษัทที่สังกัด เพื่อแจ้งซ่อมและขอใช้บริการ IT<br />
        (ชื่อและโลโก้บนเอกสารคำขอจะเป็นของบริษัทที่เลือก)
      </p>

      <div v-if="loading" class="mt-8 space-y-3" aria-busy="true" aria-label="กำลังโหลดรายชื่อบริษัท">
        <div v-for="i in 2" :key="i" class="h-[72px] animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" />
      </div>

      <div v-else-if="error" class="mt-8 rounded-2xl border border-red-200 bg-red-50 p-4 text-left text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert">
        <p class="flex items-start gap-2"><Icon name="alert" :size="18" class="mt-0.5 shrink-0" /> {{ error }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button type="button" class="btn-secondary" @click="load"><Icon name="refresh" :size="16" /> ลองใหม่</button>
          <button type="button" class="btn-ghost" @click="skip">เข้าใช้งานโดยไม่เลือกบริษัท</button>
        </div>
      </div>

      <div v-else class="mt-8 flex flex-col gap-3 text-left">
        <button
          v-for="c in companies"
          :key="c.id"
          type="button"
          :class="['flex items-center gap-4 rounded-2xl border bg-white px-4 py-3.5 transition hover:border-amber-400 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:bg-slate-900 dark:hover:border-amber-500',
            selectedCompany?.id === c.id ? 'border-amber-400 dark:border-amber-500' : 'border-slate-200 dark:border-slate-700']"
          @click="choose(c)"
        >
          <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-white ring-1 ring-slate-200 dark:ring-slate-700">
            <img v-if="companyLogoUrl(c)" :src="companyLogoUrl(c)!" :alt="`โลโก้ ${c.code}`" class="size-full object-contain p-0.5" />
            <span v-else class="text-sm font-bold text-amber-600">{{ c.code.slice(0, 2) }}</span>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block font-semibold text-slate-900 dark:text-slate-50">{{ c.name }}</span>
            <span class="block text-xs text-slate-500 dark:text-slate-400">รหัสบริษัท {{ c.code }}<template v-if="selectedCompany?.id === c.id"> · เลือกอยู่</template></span>
          </span>
          <Icon name="chevron" :size="18" class="shrink-0 text-slate-400" />
        </button>
        <div v-if="!companies?.length" class="text-center text-sm text-amber-700 dark:text-amber-300">
          ยังไม่มีบริษัทในระบบจัดซื้อ กรุณาติดต่อ IT
          <button type="button" class="btn-ghost mx-auto mt-2" @click="skip">เข้าใช้งานโดยไม่เลือกบริษัท</button>
        </div>
      </div>

      <p class="mt-8 text-xs text-slate-400">© 2026 ATTHASAN CO., LTD. — IT Hub · ข้อมูลบริษัทจากระบบจัดซื้อ</p>
    </main>
  </div>
</template>
