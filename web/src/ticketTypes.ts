import type { TicketType } from './api'

/** ไอคอนและคำอธิบายของแต่ละประเภทคำขอ (ชื่อหลักมาจาก server ผ่าน meta) */
export const typeInfo: Record<TicketType, { icon: string; desc: string; tone: string }> = {
  repair: { icon: 'wrench', desc: 'คอมเสีย เปิดไม่ติด เน็ตใช้ไม่ได้ ปริ้นเตอร์มีปัญหา', tone: 'bg-red-50 text-red-600' },
  service: { icon: 'service', desc: 'ติดตั้งโปรแกรม ลง OS ขอ VPN ขอสิทธิ์เข้าระบบ', tone: 'bg-blue-50 text-blue-600' },
  email: { icon: 'mail', desc: 'ขอเปิดใช้ E-mail @atthasan.com สำหรับพนักงาน', tone: 'bg-violet-50 text-violet-600' },
  borrow: { icon: 'laptop', desc: 'ขอเบิก-ยืม Notebook หรืออุปกรณ์ IT ชั่วคราว', tone: 'bg-amber-50 text-amber-600' },
  purchase: { icon: 'cart', desc: 'ขอซื้อคอมพิวเตอร์ จอ ปริ้นเตอร์ ซอฟต์แวร์', tone: 'bg-emerald-50 text-emerald-600' },
}

export const TICKET_NO_PATTERN = /^[A-Z]{2}-\d{4}-\d{4}$/i
