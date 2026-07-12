<template>
  <div class="relative" @mouseleave="hover = null">
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full select-none" role="img" :aria-label="ariaLabel">
      <!-- gridlines -->
      <g v-for="t in ticks" :key="t">
        <line :x1="pad.l" :x2="W - pad.r" :y1="y(t)" :y2="y(t)" stroke="#e1e0d9" stroke-width="1" />
        <text :x="pad.l - 6" :y="y(t) + 3" text-anchor="end" class="fill-[#898781]" font-size="10">
          {{ tickLabel(t) }}
        </text>
      </g>
      <!-- baseline -->
      <line :x1="pad.l" :x2="W - pad.r" :y1="y(0)" :y2="y(0)" stroke="#c3c2b7" stroke-width="1" />

      <!-- bars -->
      <g v-for="(v, i) in bars.values" :key="'b' + i">
        <rect
          :x="xBand(i) + band * 0.18" :width="band * 0.64"
          :y="v >= 0 ? y(v) : y(0)" :height="Math.max(Math.abs(y(v) - y(0)), v ? 2 : 0)"
          :rx="3" :fill="bars.color"
          :opacity="hover === null || hover === i ? 1 : 0.45"
        />
      </g>

      <!-- lines -->
      <g v-for="line in lines" :key="line.name">
        <polyline
          :points="linePoints(line.values)" fill="none"
          :stroke="line.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"
          :stroke-dasharray="line.dashed ? '5 4' : undefined"
        />
        <g v-for="(v, i) in line.values" :key="i">
          <circle
            v-if="v !== null && v !== 0"
            :cx="xMid(i)" :cy="y(v)" :r="hover === i ? 4.5 : 3"
            :fill="line.color" stroke="#ffffff" stroke-width="2"
          />
        </g>
      </g>

      <!-- crosshair -->
      <line
        v-if="hover !== null"
        :x1="xMid(hover)" :x2="xMid(hover)" :y1="pad.t" :y2="H - pad.b"
        stroke="#898781" stroke-width="1" stroke-dasharray="3 3"
      />

      <!-- x labels -->
      <text
        v-for="(l, i) in labels" :key="'x' + i"
        :x="xMid(i)" :y="H - pad.b + 14" text-anchor="middle"
        class="fill-[#898781]" font-size="10"
      >{{ l }}</text>

      <!-- hover hit targets -->
      <rect
        v-for="(l, i) in labels" :key="'h' + i"
        :x="xBand(i)" :y="pad.t" :width="band" :height="H - pad.t - pad.b"
        fill="transparent" @mouseenter="hover = i"
      />
    </svg>

    <!-- tooltip -->
    <div
      v-if="hover !== null"
      class="pointer-events-none absolute z-10 rounded-lg border border-black/10 bg-white px-3 py-2 text-xs shadow-lg"
      :style="tooltipStyle"
    >
      <p class="mb-1 font-semibold text-gray-900">{{ labels[hover] }}</p>
      <p class="flex items-center gap-1.5 text-gray-600">
        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: bars.color }" />
        {{ bars.name }}: <span class="font-medium text-gray-900">{{ fmtShort(bars.values[hover]) }}</span>
      </p>
      <p v-for="line in lines" :key="line.name" class="flex items-center gap-1.5 text-gray-600">
        <span class="inline-block h-0.5 w-2.5 rounded" :style="{ background: line.color }" />
        {{ line.name }}: <span class="font-medium text-gray-900">{{ fmtShort(line.values[hover]) }}</span>
      </p>
    </div>

    <!-- legend -->
    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
      <span class="flex items-center gap-1.5">
        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: bars.color }" /> {{ bars.name }}
      </span>
      <span v-for="line in lines" :key="line.name" class="flex items-center gap-1.5">
        <svg width="14" height="6"><line x1="0" y1="3" x2="14" y2="3" :stroke="line.color" stroke-width="2" :stroke-dasharray="line.dashed ? '4 3' : undefined" /></svg>
        {{ line.name }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  labels: { type: Array, required: true },
  bars: { type: Object, required: true }, // { name, values[], color }
  lines: { type: Array, default: () => [] }, // [{ name, values[], color, dashed }]
  ariaLabel: { type: String, default: 'Grafik tren bulanan' },
})

const { fmtShort } = useHcFormat()
const hover = ref(null)

const W = 760
const H = 260
const pad = { l: 56, r: 8, t: 10, b: 24 }

const maxVal = computed(() => {
  const all = [...props.bars.values, ...props.lines.flatMap(l => l.values)].filter(v => v !== null)
  return Math.max(...all, 1)
})

const niceMax = computed(() => {
  const raw = maxVal.value
  const mag = Math.pow(10, Math.floor(Math.log10(raw)))
  return Math.ceil(raw / (mag / 2)) * (mag / 2)
})

const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map(f => f * niceMax.value))

const band = computed(() => (W - pad.l - pad.r) / props.labels.length)
const xBand = (i) => pad.l + i * band.value
const xMid = (i) => xBand(i) + band.value / 2
const y = (v) => H - pad.b - (v / niceMax.value) * (H - pad.t - pad.b)

const linePoints = (values) => values
  .map((v, i) => (v === null || v === 0) ? null : `${xMid(i)},${y(v)}`)
  .filter(Boolean)
  .join(' ')

const tickLabel = (t) => {
  if (t >= 1e12) return (t / 1e12).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' T'
  if (t >= 1e9) return (t / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M'
  if (t >= 1e6) return (t / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' Jt'
  return t.toLocaleString('id-ID')
}

const tooltipStyle = computed(() => {
  if (hover.value === null) return {}
  const frac = (hover.value + 0.5) / props.labels.length
  return frac > 0.6
    ? { right: `${(1 - frac) * 100}%`, top: '0px', marginRight: '12px' }
    : { left: `${frac * 100}%`, top: '0px', marginLeft: '12px' }
})
</script>
