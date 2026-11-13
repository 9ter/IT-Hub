import type { BorrowRequestData,PurchaseRequestData } from '@/api'
import { meta } from '@/store'
export const PURCHASE_QUANTITY_MAX=999
export const PURCHASE_ITEM_DETAIL_MAX=255
export const PURCHASE_ITEM_OTHER_MAX=100
function detailsOf(value:unknown):Record<string,string>{
  if(!value||typeof value!=='object'||Array.isArray(value))return {}
  return Object.fromEntries(Object.entries(value).filter(([,v])=>typeof v==='string'&&v.trim()!==''))
}
/** Only the selected items' non-empty details, trimmed, as the API stores them. */
export function purchaseItemDetailsPayload(items:string[],details:Record<string,string>):Record<string,string>{
  return Object.fromEntries(items.map(k=>[k,(details[k]||'').trim()]).filter(([,v])=>v))
}
function quantitiesOf(value:unknown):Record<string,number|''>{
  if(!value||typeof value!=='object'||Array.isArray(value))return {}
  return Object.fromEntries(Object.entries(value).filter(([,v])=>typeof v==='number'))
}
export function purchaseRequestData(data:Record<string,unknown>):PurchaseRequestData {
  const text=(key:string)=>typeof data[key]==='string'?data[key] as string:''
  return {description:text('description'),position:text('position'),urgent:!!data.urgent,need_by:text('need_by'),items:Array.isArray(data.items)?data.items.filter((v):v is string=>typeof v==='string'):[],item_quantities:quantitiesOf(data.item_quantities),item_details:detailsOf(data.item_details),item_other:text('item_other'),spec_cpu:text('spec_cpu'),spec_ram:text('spec_ram'),spec_storage:text('spec_storage'),spec_display:text('spec_display'),spec_other:text('spec_other')}
}
/** Client-side check matching the API: every selected item needs a whole number 1–999. */
export function purchaseItemErrors(items:string[],quantities:Record<string,number|''>,details?:Record<string,string>,other?:string):Record<string,string>{
  if(!items.length)return {items:'กรุณาเลือกประเภทอุปกรณ์อย่างน้อย 1 รายการ'}
  const errors:Record<string,string>={}
  for(const item of items){const q=quantities[item];if(typeof q!=='number'||!Number.isInteger(q)||q<1||q>PURCHASE_QUANTITY_MAX)errors[`item_quantities.${item}`]=`กรุณาระบุจำนวนเป็นจำนวนเต็ม 1–${PURCHASE_QUANTITY_MAX}`}
  if(Object.keys(errors).length)errors.items='กรุณาระบุจำนวนของอุปกรณ์ที่เลือกให้ครบทุกรายการ'
  for(const item of items)if((details?.[item]||'').trim().length>PURCHASE_ITEM_DETAIL_MAX)errors[`item_details.${item}`]=`รายละเอียดยาวเกิน ${PURCHASE_ITEM_DETAIL_MAX} ตัวอักษร`
  if(items.includes('other')){const o=(other||'').trim();if(!o)errors.item_other='กรุณาระบุประเภทอุปกรณ์ที่ต้องการ';else if(o.length>PURCHASE_ITEM_OTHER_MAX)errors.item_other=`ประเภทอุปกรณ์ยาวเกิน ${PURCHASE_ITEM_OTHER_MAX} ตัวอักษร`}
  return errors
}
/** Item name as shown to people: "อื่นๆ: เครื่องฉายภาพ" for the free-text item. */
export function purchaseItemName(key:string,other?:unknown):string{
  const o=typeof other==='string'?other.trim():''
  return key==='other'&&o?`อื่นๆ: ${o}`:meta.value?.purchase_items[key]||key
}
/** "หมึก จำนวน 2 (HP 682 สีดำ)" per item; older requests without a stored quantity show the name only. */
export function purchaseItemLabels(items:unknown,quantities:unknown,details?:unknown,other?:unknown):string[]{
  const q=quantitiesOf(quantities),d=detailsOf(details)
  return (Array.isArray(items)?items:[]).filter((v):v is string=>typeof v==='string').map(i=>`${purchaseItemName(i,other)}${typeof q[i]==='number'?` จำนวน ${q[i]}`:''}${d[i]?` (${d[i]})`:''}`)
}
export function borrowRequestData(data:Record<string,unknown>):BorrowRequestData{
  const text=(key:string)=>typeof data[key]==='string'?data[key] as string:''
  const rows=Array.isArray(data.borrow_items)?data.borrow_items:[{device_kind:data.device_kind,item:data.item,quantity:data.quantity}]
  return {description:text('description'),start_date:text('start_date'),end_date:text('end_date'),borrow_items:rows.filter(r=>r&&typeof r==='object').map(r=>{const row=r as Record<string,unknown>;return {device_kind:row.device_kind==='notebook'||row.device_kind==='other'?row.device_kind:'',item:typeof row.item==='string'?row.item:'',quantity:typeof row.quantity==='number'?row.quantity:''}})}
}
export const BORROW_ITEMS_MAX=50,BORROW_QUANTITY_MAX=100
/** Client-side check matching the API borrow parser. */
export function borrowRequestErrors(d:BorrowRequestData):Record<string,string>{
  const errors:Record<string,string>={}
  if(!d.description.trim())errors.description='กรุณาระบุวัตถุประสงค์การใช้งาน'
  if(!d.borrow_items.length)errors.borrow_items='กรุณาเพิ่มรายการอุปกรณ์อย่างน้อย 1 รายการ'
  d.borrow_items.forEach((row,i)=>{
    if(!row.device_kind)errors[`borrow_items.${i}.device_kind`]='กรุณาเลือกประเภทอุปกรณ์'
    if(!row.item.trim())errors[`borrow_items.${i}.item`]='กรุณาระบุอุปกรณ์ที่ต้องการ'
    if(typeof row.quantity!=='number'||!Number.isInteger(row.quantity)||row.quantity<1||row.quantity>BORROW_QUANTITY_MAX)errors[`borrow_items.${i}.quantity`]=`จำนวนต้องเป็นจำนวนเต็ม 1–${BORROW_QUANTITY_MAX}`
  })
  if(!d.start_date)errors.start_date='กรุณาระบุวันที่เริ่มใช้'
  if(d.start_date&&d.end_date&&d.end_date<d.start_date)errors.end_date='วันที่คืนต้องไม่ก่อนวันที่เริ่มใช้'
  return errors
}
function borrowChanges(a:BorrowRequestData,b:BorrowRequestData){
  const items=(v:BorrowRequestData)=>v.borrow_items.map((r,i)=>`${i+1}. ${r.device_kind==='notebook'?'Notebook':'อื่นๆ'} · ${r.item} · จำนวน ${r.quantity}`).join('\n')||'—'
  const rows:[string,(v:BorrowRequestData)=>string][]=[['วัตถุประสงค์การใช้งาน',v=>v.description||'—'],['รายการอุปกรณ์',items],['วันที่เริ่มใช้',v=>v.start_date||'—'],['วันที่คืน',v=>v.end_date||'—']]
  return rows.filter(([,f])=>f(a)!==f(b)).map(([label,f])=>({label,before:f(a),after:f(b)}))
}
export function requestEditChanges(before:string,after:string):{label:string;before:string;after:string}[]{
  try{const s=JSON.parse(before);if(s?.request&&'borrow_items' in s.request){const t=JSON.parse(after);return borrowChanges(borrowRequestData({...s.request,description:s.description}),borrowRequestData({...t.request,description:t.description}))}}catch{/* fall through */}
  const read=(snapshot:string)=>{try{const s=JSON.parse(snapshot);return purchaseRequestData({...s.request,description:s.description})}catch{return purchaseRequestData({})}}
  const a=read(before),b=read(after)
  type Key=Exclude<keyof PurchaseRequestData,'item_quantities'|'item_details'|'item_other'>
  const labels:Record<Key,string>={description:'รายละเอียด / วัตถุประสงค์',position:'ตำแหน่ง',urgent:'ความเร่งด่วน',need_by:'ต้องการภายในวันที่',items:'ประเภทอุปกรณ์ / จำนวน / รายละเอียด',spec_cpu:'CPU',spec_ram:'RAM',spec_storage:'SSD / HDD',spec_display:'ขนาดจอ',spec_other:'สเปกอื่นๆ'}
  const value=(k:Key,v:PurchaseRequestData)=>k==='items'?purchaseItemLabels(v.items,v.item_quantities,v.item_details,v.item_other):v[k]
  const display=(k:Key,v:PurchaseRequestData)=>k==='urgent'?(v.urgent?'ด่วน':'ไม่ด่วน'):k==='items'?purchaseItemLabels(v.items,v.item_quantities,v.item_details,v.item_other).join(', '):String(v[k]||'—')
  return (Object.keys(labels) as Key[]).filter(k=>JSON.stringify(value(k,a))!==JSON.stringify(value(k,b))).map(k=>({label:labels[k],before:display(k,a),after:display(k,b)}))
}
