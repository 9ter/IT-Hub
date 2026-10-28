<script setup lang="ts">
import type { TicketStatus } from '@/api'
import { statusTone } from '@/format'
import { meta } from '@/store'

defineProps<{ status: TicketStatus }>()
</script>

<template>
  <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ring-1 ring-inset', statusTone[status]]">
    {{ meta?.ticket_statuses[status] ?? status }}
  </span>
</template>
