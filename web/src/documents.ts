export interface DocumentField {
  key: string; label: string; type: string; required: boolean; phase: 'request'|'completion'; options: Record<string,string>
}
export interface DocumentRole {
  key: string; label: string; phase: 'request'|'completion'; kind: 'employee'|'admin'|'external'; authority: boolean
}
export interface DocumentDefinition {
  code: string; title: string; rev: string; orientation: 'portrait'|'landscape'
  fields: DocumentField[]; columns: DocumentField[]; roles: DocumentRole[]; tablePhase: string
  record?: boolean
}
export type DocumentValue = string | string[] | null
export interface DocumentData {
  fields: Record<string,DocumentValue>; rows: Record<string,DocumentValue>[]
  signers: Record<string,{ id?: number|null; name?: string|null; email?: string|null }>
}
export interface DocumentSignature {
  id: number; role_key: string; role_label: string; phase: string; sequence_no: number
  employee_id: number|null; admin_id: number|null; signer_name: string; signer_email: string|null
  status: string; mail_status: string; mail_error: string|null; expires_at: string|null
  signature_data: string|null; signed_at: string|null; decision_note: string|null
}
export interface ITDocument {
  id: number; document_no: string; form_code: string; title: string; document_date: string; company: string
  status: string; version: number; data: DocumentData; source_note: string|null; updated_at: string
  signatures: DocumentSignature[]; logs: {note:string;created_at:string;admin_name:string|null}[]
  record_summary?: {outstanding:number;returned:number}
  borrow_source?: {ticket_id:number;ticket_no:string;admin_name:string;decided_at:string}|null
}
export function documentStateLabel(doc: ITDocument): string {
  return doc.form_code==='FM-IT-008'&&doc.status==='in_progress'?'บันทึกแล้ว':documentStates[doc.status]||doc.status
}
export const documentStates: Record<string,string> = {
  draft:'ฉบับร่าง',request_review:'รอลงนามคำขอ',in_progress:'ดำเนินการ / บันทึกผล',completion_review:'รอลงนามรับรอง',approved:'รับรองครบแล้ว',rejected:'ไม่อนุมัติ / ไม่รับรอง',cancelled:'ยกเลิก',
}
export function displayDocumentValue(field: DocumentField, value: DocumentValue|undefined): string {
  if (Array.isArray(value)) return value.map(v=>field.options[v]||v).join(', ')
  if (!value) return ''
  if (field.type==='select') return field.options[value]||value
  if (field.type==='date' || field.type==='datetime-local') {
    const m=value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}:\d{2}))?/)
    if (m) return `${m[3]}/${m[2]}/${Number(m[1])+543}${m[4]?` ${m[4]}`:''}`
  }
  return value
}
