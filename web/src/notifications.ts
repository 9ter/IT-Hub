import { computed, ref } from 'vue'
import { api, type Paged, type SignatureNotice, type TicketRow } from './api'
import { toast } from './store'

// คำขอรอรับเรื่อง (status = new) ที่บัญชีนี้ยังไม่เปิดอ่าน — ใช้ร่วมกันระหว่างปุ่มกระดิ่งแถบข้างและแถบบนมือถือ
const POLL_MS = 10_000
const LIST_LIMIT = 10

export const pendingTickets = ref<TicketRow[]>([])
export const pendingTotal = ref(0)
export const pendingLoaded = ref(false)
export const pendingFailed = ref(false)
/** ลายเซ็นผู้อนุมัติ (ทุกขั้น) ที่ลงนามแล้วแต่บัญชีนี้ยังไม่เปิดดูใบงาน */
export const signedNotices = ref<SignatureNotice[]>([])
export const signedTotal = ref(0)
/** มีคำขอใหม่เข้ามาหลังจากครั้งล่าสุดที่เปิดดูรายการ */
export const hasFreshTicket = ref(false)

// ---- เสียงแจ้งเตือน (สร้างเสียงด้วย Web Audio ไม่ต้องมีไฟล์เสียง) ----
const SOUND_KEY = 'ithub.notify.sound'
const CHIMED_KEY = 'ithub.notify.lastChimeId'
const SIGNED_KEY = 'ithub.notify.lastSignedKey'

function readStorage(key: string): string | null {
  try { return localStorage.getItem(key) } catch { return null }
}
function writeStorage(key: string, value: string) {
  try { localStorage.setItem(key, value) } catch { /* เปิดแบบส่วนตัว/ปิด storage ก็ยังใช้งานได้ */ }
}

/** เปิดเสียงเป็นค่าเริ่มต้น ปิดได้จากแผงกระดิ่งและจำไว้ในเบราว์เซอร์นี้ */
export const soundEnabled = ref(readStorage(SOUND_KEY) !== 'off')
/** เบราว์เซอร์ยังไม่ยอมให้เล่นเสียง ต้องคลิกหน้าเว็บก่อนหนึ่งครั้ง */
export const soundBlocked = ref(false)

let audioCtx: AudioContext | null = null

function getAudio(): AudioContext | null {
  if (audioCtx) return audioCtx
  const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!Ctor) return null
  try { audioCtx = new Ctor() } catch { return null }
  return audioCtx
}

// เบราว์เซอร์จะเล่นเสียงได้หลังผู้ใช้โต้ตอบกับหน้า จึงปลดล็อกตอนคลิก/กดปุ่มครั้งแรก
function unlockAudio() {
  const ctx = getAudio()
  if (!ctx) return
  if (ctx.state === 'suspended') ctx.resume().then(() => { soundBlocked.value = false }).catch(() => {})
  else soundBlocked.value = false
}

export async function playChime() {
  const ctx = getAudio()
  if (!ctx) return
  if (ctx.state !== 'running') {
    // resume() ค้างได้ถ้าเบราว์เซอร์ยังไม่อนุญาต จึงรอไม่เกิน 300ms
    await Promise.race([ctx.resume().catch(() => {}), new Promise((r) => setTimeout(r, 300))])
  }
  soundBlocked.value = ctx.state !== 'running'
  if (soundBlocked.value) return
  const start = ctx.currentTime + 0.02
  // สองโน้ตขึ้น (E6 → A6) สั้นๆ ให้รู้ว่ามีเรื่องเข้า แต่ไม่ดังจนตกใจ
  ;[[1318.5, 0], [1760, 0.16]].forEach(([freq, offset]) => {
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.value = freq
    const t = start + offset
    gain.gain.setValueAtTime(0.0001, t)
    gain.gain.exponentialRampToValueAtTime(0.25, t + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.45)
    osc.connect(gain).connect(ctx.destination)
    osc.start(t)
    osc.stop(t + 0.5)
  })
}

export function setSoundEnabled(on: boolean) {
  soundEnabled.value = on
  writeStorage(SOUND_KEY, on ? 'on' : 'off')
  if (on) {
    unlockAudio()
    playChime()
  }
}

// เปิดหลายแท็บพร้อมกันให้ดังครั้งเดียวต่อใบใหม่
function claimChime(maxId: number): boolean {
  const last = Number(readStorage(CHIMED_KEY) ?? 0) || 0
  if (maxId <= last) return false
  writeStorage(CHIMED_KEY, String(maxId))
  return true
}

// ลายเซ็นเรียงตามเวลาลงนาม (id ของใบอนุมัติสร้างตอนขอ ไม่ใช่ตอนลงนาม) จึงใช้ เวลา#id เป็นตัวเทียบ
const signedKey = (n: SignatureNotice) => `${n.signed_at}#${String(n.id).padStart(10, '0')}`
function claimSigned(key: string): boolean {
  const last = readStorage(SIGNED_KEY) ?? ''
  if (key <= last) return false
  writeStorage(SIGNED_KEY, key)
  return true
}

const baseTitle = document.title
let timer: ReturnType<typeof setInterval> | undefined
let latestRequest = 0
let newestId: number | null = null
let markingRead = 0
let latestSigned = 0
let newestSigned: string | null = null
let markingSigned = 0

/** จำนวนรวมบนกระดิ่ง = คำขอที่ยังไม่อ่าน + ลายเซ็นที่ยังไม่เห็น */
export const notifyTotal = computed(() => pendingTotal.value + signedTotal.value)

function showTitleCount() {
  document.title = notifyTotal.value > 0 ? `(${notifyTotal.value}) ${baseTitle}` : baseTitle
}

// ชื่อไฟล์ PDF ตอนสั่งพิมพ์มาจาก document.title จึงไม่ให้มีจำนวนงานติดไปด้วย
const hideTitleCount = () => { document.title = baseTitle }

export function refreshPending() {
  return Promise.all([refreshTickets(), refreshSigned()])
}

async function refreshSigned() {
  const request = ++latestSigned
  try {
    const res = await api.get<{ data: SignatureNotice[]; total: number }>('/admin/signature-notices')
    if (request !== latestSigned || markingSigned > 0) return
    const fresh = newestSigned === null ? [] : res.data.filter((n) => signedKey(n) > newestSigned!)
    const maxKey = res.data.reduce((max, n) => (signedKey(n) > max ? signedKey(n) : max), '')
    if (fresh.length) {
      hasFreshTicket.value = true
      // เปิดหลายแท็บ: แท็บที่เห็นก่อนเป็นผู้ส่งเสียงและข้อความ
      if (claimSigned(maxKey)) {
        if (soundEnabled.value) playChime()
        const n = fresh[0]
        const more = fresh.length > 1 ? ` และอีก ${fresh.length - 1} รายการ` : ''
        toast(`${n.approver_name} ${n.status === 'approved' ? 'ลงนามแล้ว' : 'ไม่อนุมัติ'} · ${n.ticket_no}${more}`, n.status === 'approved' ? 'ok' : 'error')
      }
    } else if (newestSigned === null && maxKey) {
      // โหลดครั้งแรกไม่ส่งเสียงสำหรับลายเซ็นที่ค้างอยู่ก่อนแล้ว
      claimSigned(maxKey)
    }
    if (maxKey > (newestSigned ?? '')) newestSigned = maxKey
    else newestSigned ??= ''
    signedNotices.value = res.data
    signedTotal.value = res.total
    showTitleCount()
  } catch {
    // ไม่ทับข้อความผิดพลาดของรายการคำขอ ลองใหม่รอบถัดไป
  }
}

/** เปิดหน้าใบงานแล้ว = เห็นลายเซ็นของใบนั้นทั้งหมด */
export async function markSignaturesRead(ticketId: number) {
  const before = signedNotices.value.length
  signedNotices.value = signedNotices.value.filter((n) => n.ticket_id !== ticketId)
  signedTotal.value = Math.max(0, signedTotal.value - (before - signedNotices.value.length))
  showTitleCount()
  markingSigned++
  await api.post(`/admin/tickets/${ticketId}/signatures/read`).catch(() => {})
  markingSigned--
  if (timer !== undefined) refreshSigned()
}

async function refreshTickets() {
  const request = ++latestRequest
  try {
    const res = await api.get<Paged<TicketRow>>('/admin/tickets', { status: 'new', unread: 1, per_page: LIST_LIMIT })
    // ผลที่ออกมาก่อนบันทึกการอ่านเสร็จยังมีใบนั้นอยู่ markTicketRead จะโหลดใหม่ให้เอง
    if (request !== latestRequest || markingRead > 0) return
    const maxId = res.data.reduce((max, t) => Math.max(max, t.id), 0)
    if (newestId !== null && maxId > newestId) {
      hasFreshTicket.value = true
      if (soundEnabled.value && claimChime(maxId)) playChime()
    } else if (newestId === null && maxId > 0) {
      // โหลดครั้งแรกไม่ส่งเสียงสำหรับใบที่ค้างอยู่ก่อนแล้ว
      claimChime(maxId)
    }
    newestId = Math.max(newestId ?? 0, maxId)
    pendingTickets.value = res.data
    pendingTotal.value = res.total
    pendingLoaded.value = true
    pendingFailed.value = false
    showTitleCount()
  } catch {
    if (request === latestRequest) pendingFailed.value = true
  }
}

export async function markTicketRead(id: number) {
  const index = pendingTickets.value.findIndex((t) => t.id === id)
  if (index >= 0) {
    pendingTickets.value.splice(index, 1)
    pendingTotal.value--
    showTitleCount()
  }
  markingRead++
  await api.post(`/admin/tickets/${id}/read`).catch(() => {})
  markingRead--
  if (timer !== undefined) refreshTickets()
}

const refreshWhenVisible = () => {
  if (!document.hidden) refreshPending()
}

export function startPendingPoll() {
  if (timer !== undefined) return
  refreshPending()
  timer = setInterval(refreshPending, POLL_MS)
  document.addEventListener('visibilitychange', refreshWhenVisible)
  document.addEventListener('pointerdown', unlockAudio)
  document.addEventListener('keydown', unlockAudio)
  window.addEventListener('beforeprint', hideTitleCount)
  window.addEventListener('afterprint', showTitleCount)
}

export function stopPendingPoll() {
  clearInterval(timer)
  timer = undefined
  latestRequest++
  latestSigned++
  document.removeEventListener('visibilitychange', refreshWhenVisible)
  document.removeEventListener('pointerdown', unlockAudio)
  document.removeEventListener('keydown', unlockAudio)
  window.removeEventListener('beforeprint', hideTitleCount)
  window.removeEventListener('afterprint', showTitleCount)
  pendingTickets.value = []
  pendingTotal.value = 0
  pendingLoaded.value = false
  pendingFailed.value = false
  hasFreshTicket.value = false
  newestId = null
  signedNotices.value = []
  signedTotal.value = 0
  newestSigned = null
  hideTitleCount()
}
