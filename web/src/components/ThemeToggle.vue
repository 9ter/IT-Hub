<script setup lang="ts">
import { computed } from 'vue'
import Icon from './Icon.vue'
import { isDark, toggleTheme } from '@/theme'

const label = computed(() => isDark.value ? 'เปลี่ยนเป็นธีมสว่าง' : 'เปลี่ยนเป็นธีมมืด')
</script>

<template>
  <button type="button" class="btn-ghost shrink-0 p-2 no-print" :aria-label="label" :title="label" :aria-pressed="isDark" @click="toggleTheme">
    <Icon :name="isDark ? 'sun' : 'moon'" />
  </button>
</template>
