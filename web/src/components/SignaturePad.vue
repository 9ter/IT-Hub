<script setup lang="ts">
import { onMounted, ref } from 'vue'

const model = defineModel<string>({ default: '' })
const canvas = ref<HTMLCanvasElement | null>(null)
let drawing = false
let moved = false
function point(e: PointerEvent) {
  const r = canvas.value!.getBoundingClientRect()
  return [(e.clientX-r.left)*600/r.width, (e.clientY-r.top)*200/r.height] as const
}
function start(e: PointerEvent) {
  if (e.button !== 0 || drawing) return
  e.preventDefault()
  canvas.value!.setPointerCapture(e.pointerId)
  const ctx = canvas.value!.getContext('2d')!
  ctx.beginPath()
  ctx.moveTo(...point(e))
  drawing = true
}
function move(e: PointerEvent) {
  if (!drawing) return
  const ctx = canvas.value!.getContext('2d')!
  ctx.lineTo(...point(e))
  ctx.stroke()
  moved = true
}
function finish() {
  if (!drawing) return
  drawing = false
  if (moved) model.value = canvas.value!.toDataURL('image/png')
}
function clear() {
  const ctx = canvas.value!.getContext('2d')!
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0,0,600,200)
  model.value = ''
  moved = false
  drawing = false
}
onMounted(() => {
  clear()
  const ctx = canvas.value!.getContext('2d')!
  ctx.strokeStyle = '#0f172a'
  ctx.lineWidth = 2.5
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
})
</script>

<template>
  <div>
    <canvas ref="canvas" width="600" height="200" class="block w-full touch-none rounded-xl border border-slate-300 bg-white" aria-label="ช่องวาดลายเซ็นด้วยเมาส์หรือนิ้ว" @pointerdown="start" @pointermove="move" @pointerup="finish" @pointercancel="finish" @lostpointercapture="finish" />
    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400"><span>วาดลายเซ็นด้วยเมาส์หรือนิ้วในช่องด้านบน</span><button type="button" class="text-brand-700 dark:text-brand-300 underline" @click="clear">ล้างลายเซ็น</button></div>
  </div>
</template>
