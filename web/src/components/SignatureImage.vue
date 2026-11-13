<script setup lang="ts">
import { ref, watch } from 'vue'
import { trimSignature } from '@/signatureTrim'
/** A printed signature with its blank margin cropped, so the ink fills the slot. */
const props=defineProps<{src:string;alt:string}>()
const shown=ref(props.src)
watch(()=>props.src,src=>{shown.value=src;void trimSignature(src).then(t=>{if(props.src===src)shown.value=t})},{immediate:true})
</script>
<template><img :src="shown" :alt="alt" /></template>
