const BASE = `${import.meta.env.BASE_URL}api`

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string> = {},
  ) {
    super(message)
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

async function request<T>(method: string, path: string, body?: unknown, query?: Query): Promise<T> {
  let url = BASE + path
  if (query) {
    const qs = new URLSearchParams()
    for (const [k, v] of Object.entries(query)) {
      if (v !== null && v !== undefined && v !== '') qs.set(k, String(v))
    }
    if ([...qs].length) url += '?' + qs
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  let payload: BodyInit | undefined
  if (body instanceof FormData) {
    payload = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }
  // server ต้องเห็น header นี้ในทุกคำขอที่ไม่ใช่ GET (กัน CSRF)
  if (method !== 'GET') headers['X-IT-Hub'] = '1'

  let res: Response
  try {
    res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' })
  } catch {
    throw new ApiError('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ต', 0)
  }

  const data = await res.json().catch(() => null)
  if (!res.ok) {
    if (res.status === 401 && path !== '/auth/login') onUnauthorized?.()
    throw new ApiError(data?.message ?? `เกิดข้อผิดพลาด (${res.status})`, res.status, data?.errors ?? {})
  }
  return data as T
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) {
  onUnauthorized = fn
}

export const api = {
  get: <T>(path: string, query?: Query) => request<T>('GET', path, undefined, query),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body: unknown) => request<T>('PUT', path, body),
  patch: <T>(path: string, body: unknown) => request<T>('PATCH', path, body),
  del: <T>(path: string) => request<T>('DELETE', path),
}

export const photoUrl = (ticketId: number) => `${BASE}/admin/tickets/${ticketId}/photo`

// ---------- Types ----------
export type TicketType = 'repair' | 'service' | 'email' | 'borrow' | 'purchase'
export type TicketStatus = 'new' | 'accepted' | 'in_progress' | 'waiting' | 'done' | 'rejected' | 'cancelled'
export type Priority = 'low' | 'normal' | 'high' | 'urgent'
export type AssetStatus = 'active' | 'repair' | 'spare' | 'retired'

export interface Meta {
  app_url: string
  ticket_types: Record<TicketType, { label: string; form: string }>
  ticket_statuses: Record<TicketStatus, string>
  ticket_priorities: Record<Priority, string>
  asset_statuses: Record<AssetStatus, string>
  service_actions: Record<string, string>
  device_kinds: Record<string, string>
  email_companies: Record<string, string>
  purchase_items: Record<string, string>
  categories: { id: number; name: string; icon: string }[]
}

export interface Admin {
  id: number
  username: string
  display_name: string
}

export interface AdminRow extends Admin {
  is_active: number
  last_login_at: string | null
  created_at: string
}

export interface PublicAsset {
  asset_code: string
  category: string | null
  category_icon: string | null
  company: string
  brand_model: string | null
  holder_nickname: string | null
  department: string | null
  location: string | null
  status: AssetStatus
  open_tickets: { ticket_no: string; type: TicketType; status: TicketStatus; created_at: string }[]
  recent_maintenance: { kind: 'BM' | 'PM'; performed_on: string }[]
}

export interface TicketLog {
  id?: number
  from_status: TicketStatus | null
  to_status: TicketStatus | null
  note: string | null
  is_public?: number
  created_at: string
  admin_name: string | null
}

export interface PublicTicket {
  ticket_no: string
  type: TicketType
  title: string
  status: TicketStatus
  priority: Priority
  resolution: string | null
  created_at: string
  started_at: string | null
  finished_at: string | null
  asset_code: string | null
  logs: TicketLog[]
}

export interface TicketRow {
  id: number
  ticket_no: string
  type: TicketType
  title: string
  status: TicketStatus
  priority: Priority
  requester_name: string
  requester_dept: string | null
  created_at: string
  finished_at?: string | null
  asset_code: string | null
  assigned_name: string | null
}

export interface Ticket extends TicketRow {
  description: string | null
  details: Record<string, unknown>
  asset_id: number | null
  asset_model: string | null
  requester_phone: string | null
  requester_email: string | null
  location: string | null
  assigned_to: number | null
  resolution: string | null
  fix_method: 'internal' | 'outsource' | null
  outsource_vendor: string | null
  has_photo: boolean
  started_at: string | null
  updated_at: string
  logs: TicketLog[]
}

export interface AssetRow {
  id: number
  asset_code: string
  company: string
  brand_model: string | null
  serial_no: string | null
  hostname: string | null
  holder_name: string | null
  holder_nickname: string | null
  department: string | null
  location: string | null
  status: AssetStatus
  registered_at: string | null
  category_name: string | null
  open_tickets: number
}

export interface MaintenanceRecord {
  id: number
  kind: 'BM' | 'PM'
  performed_on: string
  detail: string
  duration: string | null
  performed_by: string | null
  ticket_id: number | null
  ticket_no: string | null
}

export interface Asset extends Omit<AssetRow, 'open_tickets'> {
  category_id: number | null
  warranty_until: string | null
  specs: string | null
  notes: string | null
  tickets: { id: number; ticket_no: string; type: TicketType; title: string; status: TicketStatus; created_at: string; finished_at: string | null }[]
  maintenance: MaintenanceRecord[]
}

export interface Paged<T> {
  data: T[]
  total: number
  page: number
  per_page: number
}

export interface Dashboard {
  by_status: Partial<Record<TicketStatus, number>>
  open_by_type: Partial<Record<TicketType, number>>
  open_total: number
  created_today: number
  created_month: number
  done_month: number
  avg_minutes_30d: number | null
  queue: TicketRow[]
  assets_by_category: Record<string, number>
}
