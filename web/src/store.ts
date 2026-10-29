import { reactive, ref } from 'vue'
import { api, type Admin, type Meta } from './api'

// ---------- ตัวเลือก/ป้ายกำกับจาก server (โหลดครั้งเดียว) ----------
export const meta = ref<Meta | null>(null)
let metaPromise: Promise<Meta> | null = null

export function loadMeta(): Promise<Meta> {
  metaPromise ??= api.get<Meta>('/meta').then((m) => (meta.value = m)).catch((e) => {
    metaPromise = null
    throw e
  })
  return metaPromise
}

// ---------- ผู้ดูแลที่ login อยู่ ----------
export const auth = reactive<{ admin: Admin | null; checked: boolean }>({ admin: null, checked: false })

export async function checkAuth(): Promise<Admin | null> {
  if (!auth.checked) {
    const res = await api.get<{ admin: Admin | null }>('/auth/me')
    auth.admin = res.admin
    auth.checked = true
  }
  return auth.admin
}

// ---------- คำขอที่เคยส่งจากเครื่องนี้ (เก็บในเบราว์เซอร์) ----------
const MY_KEY = 'ithub.my-tickets'

export function myTickets(): string[] {
  try {
    const list = JSON.parse(localStorage.getItem(MY_KEY) ?? '[]')
    return Array.isArray(list) ? list.filter((x) => typeof x === 'string') : []
  } catch {
    return []
  }
}

export function rememberTicket(no: string) {
  try {
    localStorage.setItem(MY_KEY, JSON.stringify([no, ...myTickets().filter((x) => x !== no)].slice(0, 20)))
  } catch {
    // ไม่มี localStorage (private mode) — ข้ามได้
  }
}

export function forgetTicket(no: string) {
  try {
    localStorage.setItem(MY_KEY, JSON.stringify(myTickets().filter((x) => x !== no)))
  } catch {
    // ignore
  }
}

// ---------- ข้อมูลผู้แจ้งล่าสุด (กรอกให้อัตโนมัติครั้งถัดไป) ----------
const REQUESTER_KEY = 'ithub.requester'
export interface RequesterInfo {
  requester_name: string
  requester_dept: string
  requester_phone: string
  requester_email: string
  position: string
}

export function savedRequester(): Partial<RequesterInfo> {
  try {
    return JSON.parse(localStorage.getItem(REQUESTER_KEY) ?? '{}') ?? {}
  } catch {
    return {}
  }
}

export function saveRequester(info: RequesterInfo) {
  try {
    localStorage.setItem(REQUESTER_KEY, JSON.stringify(info))
  } catch {
    // ignore
  }
}

// ---------- แจ้งเตือนมุมจอ ----------
export const toasts = ref<{ id: number; text: string; kind: 'ok' | 'error' }[]>([])
let toastId = 0
export function toast(text: string, kind: 'ok' | 'error' = 'ok') {
  const id = ++toastId
  toasts.value.push({ id, text, kind })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3500)
}
