import QRCode from 'qrcode'
import { meta } from './store'

/** URL ที่ฝังใน QR ของอุปกรณ์ — ใช้ app_url จาก config ฝั่ง server เพื่อให้มือถือเปิดได้จากทุกที่ */
export function assetUrl(code: string): string {
  const base = (meta.value?.app_url || window.location.origin + import.meta.env.BASE_URL).replace(/\/+$/, '')
  return `${base}/a/${encodeURIComponent(code)}`
}

export function qrDataUrl(text: string, size = 320): Promise<string> {
  return QRCode.toDataURL(text, { width: size, margin: 1, errorCorrectionLevel: 'M' })
}
