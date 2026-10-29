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

/** Numeric Buddhist-calendar date used on FM-IT printed forms (server time is Bangkok). */
export function fmtFormDate(s: string | null | undefined): string {
  const match = s?.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${Number(match[1]) + 543}` : ''
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
  new: 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 ring-amber-200 dark:ring-amber-800',
  accepted: 'bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-300 ring-sky-200 dark:ring-sky-800',
  in_progress: 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-300 ring-blue-200 dark:ring-blue-800',
  waiting: 'bg-violet-100 dark:bg-violet-900/40 text-violet-800 dark:text-violet-300 ring-violet-200 dark:ring-violet-800',
  done: 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 ring-emerald-200 dark:ring-emerald-800',
  rejected: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 ring-red-200 dark:ring-red-800',
  cancelled: 'bg-slate-100 dark:bg-slate-900 text-slate-600 dark:text-slate-300 ring-slate-200 dark:ring-slate-700',
}

export const priorityTone: Record<Priority, string> = {
  low: 'text-slate-500 dark:text-slate-400',
  normal: 'text-slate-700 dark:text-slate-200',
  high: 'text-orange-600 dark:text-orange-400 font-semibold',
  urgent: 'text-red-600 dark:text-red-400 font-bold',
}
