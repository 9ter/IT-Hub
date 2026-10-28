import type { Priority, TicketStatus } from './api'

const dtf = new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' })
const df = new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: 'numeric' })

const parse = (s: string) => new Date(s.replace(' ', 'T'))

export function fmtDateTime(s: string | null | undefined): string {
  return s ? dtf.format(parse(s)) : '-'
}

export function fmtDate(s: string | null | undefined): string {
  return s ? df.format(parse(s.length === 10 ? s + 'T00:00:00' : s)) : '-'
}

export function timeAgo(s: string): string {
  const mins = Math.round((Date.now() - parse(s).getTime()) / 60000)
  if (mins < 1) return 'เมื่อสักครู่'
  if (mins < 60) return `${mins} นาทีที่แล้ว`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours} ชม.ที่แล้ว`
  const days = Math.round(hours / 24)
  return days < 30 ? `${days} วันที่แล้ว` : fmtDate(s)
}

export function fmtMinutes(mins: number | null): string {
  if (mins === null) return '-'
  if (mins < 60) return `${mins} นาที`
  if (mins < 1440) return `${(mins / 60).toFixed(1)} ชม.`
  return `${(mins / 1440).toFixed(1)} วัน`
}

/** ค่าในรูปแบบ <input type="datetime-local"> ของเวลาปัจจุบัน */
export function nowLocalInput(): string {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 16)
}

export function todayInput(): string {
  return nowLocalInput().slice(0, 10)
}

export const statusTone: Record<TicketStatus, string> = {
  new: 'bg-amber-100 text-amber-800 ring-amber-200',
  accepted: 'bg-sky-100 text-sky-800 ring-sky-200',
  in_progress: 'bg-blue-100 text-blue-800 ring-blue-200',
  waiting: 'bg-violet-100 text-violet-800 ring-violet-200',
  done: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
  rejected: 'bg-red-100 text-red-700 ring-red-200',
  cancelled: 'bg-slate-100 text-slate-600 ring-slate-200',
}

export const priorityTone: Record<Priority, string> = {
  low: 'text-slate-500',
  normal: 'text-slate-700',
  high: 'text-orange-600 font-semibold',
  urgent: 'text-red-600 font-bold',
}
