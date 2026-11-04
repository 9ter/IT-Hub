import type { PurchaseRequestData } from '@/api'
import { meta } from '@/store'
export function purchaseRequestData(data:Record<string,unknown>):PurchaseRequestData {
  const text=(key:string)=>typeof data[key]==='string'?data[key] as string:''
  return {description:text('description'),position:text('position'),urgent:!!data.urgent,need_by:text('need_by'),items:Array.isArray(data.items)?data.items.filter((v):v is string=>typeof v==='string'):[],spec_cpu:text('spec_cpu'),spec_ram:text('spec_ram'),spec_storage:text('spec_storage'),spec_display:text('spec_display'),spec_other:text('spec_other')}
}
export function requestEditChanges(before:string,after:string):{label:string;before:string;after:string}[]{
  const read=(snapshot:string)=>{try{const s=JSON.parse(snapshot);return purchaseRequestData({...s.request,description:s.description})}catch{return purchaseRequestData({})}}
  const a=read(before),b=read(after)
  const labels:Record<keyof PurchaseRequestData,string>={description:'รายละเอียด / วัตถุประสงค์',position:'ตำแหน่ง',urgent:'ความเร่งด่วน',need_by:'ต้องการภายในวันที่',items:'ประเภทอุปกรณ์',spec_cpu:'CPU',spec_ram:'RAM',spec_storage:'SSD / HDD',spec_display:'ขนาดจอ',spec_other:'สเปกอื่นๆ'}
  const display=(k:keyof PurchaseRequestData,v:PurchaseRequestData)=>k==='urgent'?(v.urgent?'ด่วน':'ไม่ด่วน'):k==='items'?v.items.map(i=>meta.value?.purchase_items[i]||i).join(', '):String(v[k]||'—')
  return (Object.keys(labels) as (keyof PurchaseRequestData)[]).filter(k=>JSON.stringify(a[k])!==JSON.stringify(b[k])).map(k=>({label:labels[k],before:display(k,a),after:display(k,b)}))
}
