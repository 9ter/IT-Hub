import { createApp,defineComponent,h,ref } from 'vue'
import TicketDetail from './views/admin/TicketDetail.vue'
import DocumentPrint from './components/DocumentPrint.vue'
import { meta,auth } from './store'
import type { Meta,Ticket } from './api'
import type { ITDocument,DocumentDefinition } from './documents'
import './style.css'
const payload=(window as unknown as {__archive?:{ticket:Ticket;meta:Meta;document?:ITDocument;definition?:DocumentDefinition}}).__archive
if(payload){
  meta.value=payload.meta;auth.admin={id:0,username:'archive',display_name:'IT Hub'}
  const app=createApp(defineComponent({setup(){const print=ref<InstanceType<typeof DocumentPrint>|null>(null);return()=>payload.document&&payload.definition?
    h('div',[h('button',{class:'no-print',onClick:async()=>{await print.value?.prepare();window.print()}},'พิมพ์ฟอร์ม'),h(DocumentPrint,{ref:print,document:payload.document,definition:payload.definition})]):h(TicketDetail,{id:payload.ticket.id})}}))
  app.component('RouterLink',defineComponent({setup(_,ctx){return()=>h('span',ctx.slots.default?.())}}))
  app.mount('#app')
}
