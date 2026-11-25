import type { DocumentDefinition, ITDocument } from './documents'
import { fmtFormDate, todayInput } from './format'

/** FM-IT-005 รายงานการ BACK UP ข้อมูล SERVER — one row per backup run (/admin/backups). */
export interface BackupRow {
  id: number; backup_date: string; server: string; dataset: string
  data_size: string | null; source: string | null; device: string | null; folder: string | null; backup_size: string | null
  started_at: string | null; finished_at: string | null; maker: string | null; acknowledger: string | null; notes: string | null
  updated_at?: string | null; updated_by_name?: string | null
}
export type BackupSuggestKey = 'server' | 'dataset' | 'source' | 'device' | 'folder' | 'maker' | 'acknowledger'
export interface BackupYear {
  year: number; current_year: number; years: { year: number; count: number }[]; rows: BackupRow[]; servers: string[]
  last: BackupRow | null; suggest: Record<BackupSuggestKey, string[]>; form: DocumentDefinition
}
export const BACKUP_FORM_CODE = 'FM-IT-005'
export const BACKUP_COMPANY = 'บริษัท อัตถสาร จำกัด'

/** A print-only FM-IT-005 document of one year and one server; nothing is saved as a document. */
export function backupPrintDocument(year: number, server: string, rows: BackupRow[], definition: DocumentDefinition): ITDocument {
  return {
    id: 0, document_no: '-', form_code: BACKUP_FORM_CODE, title: definition.title, document_date: todayInput(), company: BACKUP_COMPANY,
    status: 'draft', version: 1, source_note: null, updated_at: '', signatures: [], logs: [],
    data: {
      fields: { year: String(year), server }, signers: {},
      rows: rows.map(r => ({
        date: fmtFormDate(r.backup_date), dataset: r.dataset, size: r.data_size, source: r.source, device: r.device, folder: r.folder,
        backup_size: r.backup_size, started: r.started_at, finished: r.finished_at, maker: r.maker, acknowledger: r.acknowledger, notes: r.notes,
      })),
    },
  }
}


/** Saved Synology Hyper Backup connection (no password). */
export interface HyperBackupConnection {
  revision: string; host: string; port: number; username: string; fingerprint: string; task_id: number; task_name: string
  defaults: { server: string; dataset: string; source: string | null; device: string | null; folder: string | null }
  verified_at: string; last_sync: { at: string; added: number; message: string; ok: boolean } | null
}
export interface HyperBackupTask { task_id: number; name: string; state: string; last_bkp_time: string; last_bkp_result: string; next_bkp_time: string }
