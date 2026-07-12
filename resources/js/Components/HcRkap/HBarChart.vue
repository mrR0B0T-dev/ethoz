<template>
  <div class="space-y-2">
    <div
      v-for="item in items" :key="item.label"
      class="group grid grid-cols-[7rem_1fr_auto] items-center gap-2 text-xs"
      :title="`${item.label}: ${fmtIDR(item.value)}`"
    >
      <span class="truncate text-gray-600" :title="item.name || item.label">{{ item.label }}</span>
      <div class="relative h-4 overflow-hidden rounded-sm bg-gray-100">
        <div
          class="absolute inset-y-0 left-0 rounded-r-sm transition-all group-hover:brightness-110"
          :style="{ width: pct(item.value) + '%', background: color }"
        />
        <div
          v-if="item.secondary"
          class="absolute bottom-0 left-0 h-1.5 rounded-r-sm"
          :style="{ width: pct(item.secondary) + '%', background: secondaryColor }"
        />
      </div>
      <span class="w-20 text-right font-medium tabular-nums text-gray-900">{{ fmtShort(item.value) }}</span>
    </div>
    <div v-if="hasSecondary" class="flex items-center gap-4 pt-1 text-[11px] text-gray-500">
      <span class="flex items-center gap-1.5"><span class="h-2 w-2.5 rounded-sm" :style="{ background: color }" /> {{ label }}</span>
      <span class="flex items-center gap-1.5"><span class="h-1.5 w-2.5 rounded-sm" :style="{ background: secondaryColor }" /> {{ secondaryLabel }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  items: { type: Array, required: true }, // [{ label, value, secondary? }]
  color: { type: String, default: '#2a78d6' },
  secondaryColor: { type: String, default: '#1baf7a' },
  label: { type: String, default: 'RKAP' },
  secondaryLabel: { type: String, default: 'Realisasi' },
})

const { fmtShort, fmtIDR } = useHcFormat()
const max = computed(() => Math.max(...props.items.map(i => i.value), 1))
const pct = (v) => Math.max(0, v / max.value * 100)
const hasSecondary = computed(() => props.items.some(i => i.secondary))
</script>
