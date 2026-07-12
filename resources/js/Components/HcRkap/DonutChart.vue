<template>
  <div class="flex flex-col items-center gap-4">
    <div class="relative shrink-0" @mouseleave="hover = null">
      <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" role="img" :aria-label="ariaLabel">
        <g v-for="(seg, i) in segments" :key="i">
          <path
            :d="seg.path" :fill="seg.color"
            stroke="#ffffff" stroke-width="2"
            :opacity="hover === null || hover === i ? 1 : 0.4"
            class="cursor-default transition-opacity"
            @mouseenter="hover = i"
          />
        </g>
        <text :x="size / 2" :y="size / 2 - 6" text-anchor="middle" class="fill-[#52514e]" font-size="10">
          Total
        </text>
        <text :x="size / 2" :y="size / 2 + 12" text-anchor="middle" class="fill-[#0b0b0b] font-semibold" font-size="13">
          {{ fmtShort(total) }}
        </text>
      </svg>
      <div
        v-if="hover !== null"
        class="pointer-events-none absolute left-1/2 top-0 z-10 -translate-x-1/2 -translate-y-2 whitespace-nowrap rounded-lg border border-black/10 bg-white px-3 py-1.5 text-xs shadow-lg"
      >
        <span class="font-semibold text-gray-900">{{ items[hover].label }}</span>
        <span class="text-gray-600"> · {{ fmtShort(items[hover].value) }} ({{ share(items[hover].value) }}%)</span>
      </div>
    </div>

    <ul class="w-full min-w-0 space-y-1.5 text-xs">
      <li
        v-for="(item, i) in items" :key="item.label"
        class="flex items-center gap-2 rounded px-1 py-0.5"
        :class="hover === i ? 'bg-gray-50' : ''"
        @mouseenter="hover = i" @mouseleave="hover = null"
      >
        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: item.color }" />
        <span class="min-w-0 flex-1 truncate text-gray-700">{{ item.label }}</span>
        <span class="font-medium tabular-nums text-gray-900">{{ fmtShort(item.value) }}</span>
        <span class="w-10 text-right tabular-nums text-gray-400">{{ share(item.value) }}%</span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  items: { type: Array, required: true }, // [{ label, value, color }]
  size: { type: Number, default: 180 },
  ariaLabel: { type: String, default: 'Komposisi anggaran' },
})

const { fmtShort } = useHcFormat()
const hover = ref(null)

const total = computed(() => props.items.reduce((s, x) => s + x.value, 0))
const share = (v) => total.value > 0 ? (v / total.value * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 }) : 0

const segments = computed(() => {
  const cx = props.size / 2
  const r = props.size / 2 - 4
  const inner = r * 0.62
  let angle = -Math.PI / 2
  return props.items.map((item) => {
    const frac = total.value > 0 ? item.value / total.value : 0
    const a0 = angle
    const a1 = angle + frac * Math.PI * 2
    angle = a1
    const large = a1 - a0 > Math.PI ? 1 : 0
    const p = (a, rad) => `${cx + rad * Math.cos(a)},${cx + rad * Math.sin(a)}`
    return {
      color: item.color,
      path: `M ${p(a0, r)} A ${r} ${r} 0 ${large} 1 ${p(a1, r)} L ${p(a1, inner)} A ${inner} ${inner} 0 ${large} 0 ${p(a0, inner)} Z`,
    }
  })
})
</script>
