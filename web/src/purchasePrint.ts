import type { Ticket } from './api'
import type { DocumentSignature, ITDocument } from './documents'

/** A print-only view of a ticket; no document record or substitute signature is created. */
export function purchasePrintDocument(ticket:Ticket):ITDocument {
  const d=ticket.details
  const text=(key:string)=>typeof d[key]==='string'?d[key] as string:null
  const signatures:DocumentSignature[]=[]
  const add=(role:string,name:string|null|undefined,image:string|null|undefined,date:string|null|undefined,status:string,person:{employee_id?:number|null;admin_id?:number|null}={})=>{
    if(!name&&!image)return
    const def=ticket.print_form?.roles.find(r=>r.key===role)
    signatures.push({id:signatures.length+1,role_key:role,role_label:def?.label||role,phase:def?.phase||'request',sequence_no:signatures.length+1,employee_id:person.employee_id||null,admin_id:person.admin_id||null,signer_name:name||'',signer_email:null,status,mail_status:'pending',mail_error:null,expires_at:null,signature_data:image||null,signed_at:date||null,decision_note:role==='head'?ticket.approval?.decision_note||null:null})
  }
  add('requester',ticket.requester_signature?.requester_name||ticket.requester_name,ticket.requester_signature?.signature_data,ticket.requester_signature?.signed_at,ticket.requester_signature?'approved':'waiting')
  add('head',ticket.approval?.approver_name||text('approver_name'),ticket.approval?.signature_data,ticket.approval?.signed_at,ticket.approval?.status||'waiting',{employee_id:ticket.approval?.employee_id})
  add('receiver',ticket.receipt_signature?.signer_name,ticket.receipt_signature?.signature_data,ticket.receipt_signature?.signed_at,ticket.receipt_signature?'approved':'waiting',{admin_id:ticket.receipt_signature?.admin_id})
  for(const role of ['director','purchasing','md'] as const){
    const approval=ticket.purchase_approvals?.[role]
    if(approval?.current){add(role,approval.approver_name,approval.signature_data,approval.signed_at,approval.status,{employee_id:approval.employee_id});signatures[signatures.length-1]!.decision_note=approval.decision_note}
  }
  return {
    id:ticket.id,document_no:ticket.ticket_no,form_code:'FM-IT-007',title:'ใบขอซื้อครุภัณฑ์คอมพิวเตอร์',document_date:ticket.created_at.slice(0,10),company:'บริษัท อัตถสาร จำกัด',status:'draft',version:1,source_note:null,updated_at:ticket.updated_at,logs:[],signatures,
    data:{fields:{requester:ticket.requester_name,department:ticket.requester_dept,position:text('position'),urgency:d.urgent?'urgent':'normal',need_by:text('need_by'),purpose:ticket.description,cpu:text('spec_cpu'),ram:text('spec_ram'),storage:text('spec_storage'),display:text('spec_display'),other:text('spec_other'),result:ticket.resolution,...ticket.purchase_fields},rows:(Array.isArray(d.items)?d.items:[]).filter((item):item is string=>typeof item==='string').map(item=>({item,quantity:null,spec:null})),signers:{}},
  }
}
