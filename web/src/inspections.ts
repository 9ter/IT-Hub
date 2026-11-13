// FM-IT-002 ใบตรวจสอบโครงสร้างพื้นฐาน — yearly checklist edited in place.
export type InspectionResult = 'normal' | 'abnormal' | 'fixed'
export interface InspectionAsset {
  code: string; model: string | null; category: string | null; company: string; holder: string | null; department: string | null
  status: 'active' | 'repair' | 'spare' | 'retired'
}
export interface InspectionItem {
  id: number; sort_order: number; asset_id: number | null; asset_code: string | null; item: string | null; notes: string | null
  asset: InspectionAsset | null; results: Partial<Record<number, InspectionResult>>
}
export interface InspectionMonth {
  inspected_on: string | null; inspector: string | null; reviewer: string | null; notes: string | null
  updated_at: string; updated_by_name: string | null
}
export interface InspectionYear {
  year: number; current_year: number; current_month: number
  years: { year: number; items: number }[]
  items: InspectionItem[]; months: Partial<Record<number, InspectionMonth>>; names: string[]
  form: { code: string; title: string; rev: string }
  added?: number
}

export const inspectionMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.']
export const inspectionMarks: Record<InspectionResult, string> = { normal: '/', abnormal: 'X', fixed: '✓' }
export const inspectionLabels: Record<InspectionResult, string> = { normal: 'ปกติ', abnormal: 'ผิดปกติ', fixed: 'แก้ไขแล้ว' }
/** Name printed in the "รายการตรวจสอบ" column: what the form said, else the register category. */
export const inspectionItemName = (i: InspectionItem) => i.item || i.asset?.category || ''
