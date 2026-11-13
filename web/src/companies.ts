import { ref } from 'vue'
import { api } from './api'
import atsLogo from '@/assets/ats-logo.png'

/** บริษัทจากระบบจัดซื้อ (E-Procurement) — อ่านอย่างเดียวผ่าน /api/companies */
export interface Company {
  id: number
  code: string
  name: string
  name_en: string | null
  logo_version: string | null
}

/** บริษัทที่ผู้แจ้งเลือก เก็บไว้ในรายละเอียดคำขอ */
export interface RequesterCompany { id: number; code: string; name: string }

export const DEFAULT_COMPANY_NAME = 'บริษัท อัตถสาร จำกัด'

export const companyLogoUrl = (c: Pick<Company, 'id' | 'logo_version'>) =>
  c.logo_version ? `${import.meta.env.BASE_URL}api/companies/${c.id}/logo?v=${c.logo_version}` : null

// ---------- รายการบริษัท (โหลดครั้งเดียว) ----------
export const companies = ref<Company[] | null>(null)
export const companiesFailed = ref(false)
let pending: Promise<Company[]> | null = null

export function loadCompanies(): Promise<Company[]> {
  pending ??= api.get<Company[]>('/companies').then((list) => {
    companies.value = list
    companiesFailed.value = false
    // บริษัทที่เคยเลือกถูกปิดใช้งาน/ลบในระบบจัดซื้อ → ให้เลือกใหม่, ชื่อ/โลโก้เปลี่ยน → อัปเดตตามต้นทาง
    const sel = selectedCompany.value
    if (sel) {
      const fresh = list.find((c) => c.id === sel.id)
      if (!fresh) clearCompany()
      else if (fresh.name !== sel.name || fresh.code !== sel.code || fresh.logo_version !== sel.logo_version) selectCompany(fresh)
    }
    return list
  }).catch((e) => {
    pending = null
    companiesFailed.value = true
    throw e
  })
  return pending
}

// ---------- บริษัทที่เลือก (จำในเบราว์เซอร์ เหมือนหน้าเลือกบริษัทของระบบจัดซื้อ) ----------
const KEY = 'ithub.company'

function readSelected(): Company | null {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) ?? 'null')
    return v && typeof v.id === 'number' && typeof v.name === 'string' && typeof v.code === 'string'
      ? { id: v.id, code: v.code, name: v.name, name_en: v.name_en ?? null, logo_version: v.logo_version ?? null }
      : null
  } catch {
    return null
  }
}

export const selectedCompany = ref<Company | null>(readSelected())
/** เบราว์เซอร์ที่บล็อก localStorage ยังเลือกได้ในแท็บนี้ */
export const companySkipped = ref(false)

export function selectCompany(c: Company) {
  selectedCompany.value = { ...c }
  try { localStorage.setItem(KEY, JSON.stringify(c)) } catch { /* private mode */ }
}

export function clearCompany() {
  selectedCompany.value = null
  try { localStorage.removeItem(KEY) } catch { /* ignore */ }
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => { if (e.key === KEY) selectedCompany.value = readSelected() })
}

// ---------- หัวเอกสาร: ชื่อ + โลโก้ตามบริษัท ----------
const norm = (s: string | null | undefined) => (s ?? '').replace(/\s+/g, ' ').trim()

/**
 * โลโก้สำหรับฉบับพิมพ์ตามชื่อ/รหัสบริษัทของเอกสาร
 * - พบในรายการจากระบบจัดซื้อ → ใช้โลโก้ต้นทาง
 * - ไม่พบ (เช่น ชื่อรวมหลายบริษัท) → โลโก้ ATS เดิม
 * - โหลดรายการไม่ได้และไม่ใช่ ATS → ไม่แสดงโลโก้ (ไม่พิมพ์โลโก้ผิดบริษัท)
 */
export function companyLogo(name?: string | null, id?: number | null): string | null {
  const list = companies.value
  if (list) {
    const c = (id ? list.find((x) => x.id === id) : undefined) ?? list.find((x) => norm(x.name) === norm(name))
    if (c) return companyLogoUrl(c) ?? atsLogo
    return atsLogo
  }
  return !norm(name) || norm(name) === DEFAULT_COMPANY_NAME ? atsLogo : null
}

/** บริษัทของใบงาน (ใบเก่าก่อนมีการเลือกบริษัท = ATS ตามเดิม) */
export function ticketCompany(details: Record<string, unknown> | null | undefined): RequesterCompany | null {
  const c = details?.requester_company as RequesterCompany | undefined
  return c && typeof c.name === 'string' ? c : null
}

/** ใช้ใน prepare() ของฉบับพิมพ์: รอรายการบริษัทก่อนรอรูป (ล้มเหลวก็พิมพ์ต่อด้วยโลโก้สำรอง) */
export const ensureCompanies = () => loadCompanies().catch(() => [])
