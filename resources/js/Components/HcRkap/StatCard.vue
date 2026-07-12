<template>
  <div class="rounded-xl border border-gray-200 bg-white p-4">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ label }}</p>
    <p class="mt-1.5 text-2xl font-semibold text-gray-900">{{ value }}</p>
    <p v-if="hint" class="mt-1 flex items-center gap-1 text-xs" :class="hintClass">
      <component :is="hintIcon" v-if="hintIcon" class="h-3.5 w-3.5" />
      {{ hint }}
    </p>
    <slot />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { ArrowTrendingUpIcon, ArrowTrendingDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  label: String,
  value: String,
  hint: String,
  trend: { type: String, default: null }, // 'up' | 'down' | null
  trendGood: { type: Boolean, default: false },
})

const hintIcon = computed(() =>
  props.trend === 'up' ? ArrowTrendingUpIcon : props.trend === 'down' ? ArrowTrendingDownIcon : null)

const hintClass = computed(() => {
  if (!props.trend) return 'text-gray-500'
  return props.trendGood ? 'text-emerald-700' : 'text-red-600'
})
</script>
