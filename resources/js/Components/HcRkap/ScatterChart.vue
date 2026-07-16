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

      <!-- area min–max struktur -->
      <polygon :points="bandArea" fill="#2a78d6" opacity="0.07" />

      <!-- garis struktur: min & max (putus-putus), mid (solid) -->
      <polyline :points="bandLine('min')" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-dasharray="5 4" stroke-linejoin="round" />
      <polyline :points="bandLine('max')" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-dasharray="5 4" stroke-linejoin="round" />
      <polyline :points="bandLine('mid')" fill="none" stroke="#1c5cab" stroke-width="2" stroke-linejoin="round" />

      <!-- titik pegawai -->
      <g v-for="p in placed" :key="p.id">
        <circle
          :cx="p.x" :cy="p.y" :r="hover?.id === p.id ? 5.5 : 4"
          :fill="BAND_COLOR[p.band]" stroke="#ffffff" stroke-width="1.5"
          :opacity="hover === null || hover.id === p.id ? 0.9 : 0.35"
        />
      </g>
      <!-- hit target lebih besar dari titiknya -->
      <circle
        v-for="p in placed" :key="'h' + p.id"
        :cx="p.x" :cy="p.y" r="8" fill="transparent"
        @mouseenter="hover = p"
      />

      <!-- label sumbu x: kode grade -->
      <text
        v-for="b in sortedBands" :key="'x' + b.level"
        :x="xMid(b.level)" :y="H - pad.b + 14" text-anchor="middle"
        class="fill-[#898781]" font-size="9"
      >{{ b.code }}</text>
    </svg>

    <!-- tooltip -->
    <div
      v-if="hover"
      class="pointer-events-none absolute z-10 rounded-lg border border-black/10 bg-white px-3 py-2 text-xs shadow-lg"
      :style="tooltipStyle"
    >
      <p class="mb-0.5 font-semibold text-gray-900">{{ hover.name }}</p>
      <p class="text-gray-600">Grade <span class="font-medium text-gray-900">{{ hover.code }}</span> · Level {{ hover.level }}</p>
      <p class="text-gray-600">Gaji dasar: <span class="font-medium tabular-nums text-gray-900">{{ fmtIDR(hover.salary) }}</span></p>
      <p class="flex items-center gap-1.5 text-gray-600">
        <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: BAND_COLOR[hover.band] }" />
        {{ BAND_LABEL[hover.band] }}
      </p>
    </div>

    <!-- legend -->
    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
      <span v-for="(label, key) in BAND_LABEL" :key="key" class="flex items-center gap-1.5">
        <span class="inline-block h-2.5 w-2.5 rounded-full border border-white shadow-sm" :style="{ background: BAND_COLOR[key] }" />
        {{ label }}
      </span>
      <span class="flex items-center gap-1.5">
        <svg width="16" height="6"><line x1="0" y1="3" x2="16" y2="3" stroke="#1c5cab" stroke-width="2" /></svg> Mid struktur
      </span>
      <span class="flex items-center gap-1.5">
        <svg width="16" height="6"><line x1="0" y1="3" x2="16" y2="3" stroke="#9ca3af" stroke-width="1.5" stroke-dasharray="4 3" /></svg> Min / Max struktur
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  // titik pegawai: { id, name, level(1..n), code, salary, band: 'dalam'|'bawah'|'atas' }
  points: { type: Array, required: true },
  // struktur per grade: { level, code, min, mid, max } terurut level
  bands: { type: Array, required: true },
  ariaLabel: { type: String, default: 'Sebaran gaji dasar pegawai terhadap struktur upah per grade' },
})

const { fmtIDR } = useHcFormat()
const hover = ref(null)

const BAND_COLOR = { dalam: '#2a78d6', bawah: '#f59e0b', atas: '#b91c1c' }
const BAND_LABEL = { dalam: 'Dalam rentang', bawah: 'Di bawah min', atas: 'Di atas max' }

const W = 760
const H = 320
const pad = { l: 58, r: 10, t: 12, b: 30 }

// urutkan berdasar level dan petakan level → posisi kolom, agar level
// tidak berurutan (mis. setelah hapus grade) tetap tergambar rapat
const sortedBands = computed(() => [...props.bands].sort((a, b) => a.level - b.level))
const levelIndex = computed(() => new Map(sortedBands.value.map((b, i) => [b.level, i])))
const bandW = computed(() => (W - pad.l - pad.r) / Math.max(sortedBands.value.length, 1))
const xMid = (level) => pad.l + ((levelIndex.value.get(level) ?? 0) + 0.5) * bandW.value

const maxVal = computed(() => Math.max(
  ...props.points.map(p => p.salary),
  ...props.bands.map(b => b.max),
  1,
))
const niceMax = computed(() => {
  const mag = Math.pow(10, Math.floor(Math.log10(maxVal.value)))
  return Math.ceil(maxVal.value / (mag / 2)) * (mag / 2)
})
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map(f => f * niceMax.value))
const y = (v) => H - pad.b - (v / niceMax.value) * (H - pad.t - pad.b)

// sebaran horizontal deterministik di dalam band agar titik tidak menumpuk
const jitter = (id) => (((id * 2654435761) % 1000) / 1000 - 0.5) * bandW.value * 0.55

const placed = computed(() => props.points
  .filter(p => levelIndex.value.has(p.level))
  .map(p => ({
    ...p,
    x: xMid(p.level) + jitter(p.id),
    y: y(p.salary),
  })))

const bandLine = (key) => sortedBands.value
  .map(b => `${xMid(b.level)},${y(b[key])}`)
  .join(' ')

const bandArea = computed(() => {
  const top = sortedBands.value.map(b => `${xMid(b.level)},${y(b.max)}`)
  const bottom = [...sortedBands.value].reverse().map(b => `${xMid(b.level)},${y(b.min)}`)
  return [...top, ...bottom].join(' ')
})

const tickLabel = (t) => {
  if (t >= 1e9) return (t / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M'
  if (t >= 1e6) return (t / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' Jt'
  return t.toLocaleString('id-ID')
}

const tooltipStyle = computed(() => {
  if (!hover.value) return {}
  const fx = hover.value.x / W
  const fy = Math.max(0, hover.value.y / H - 0.08)
  return fx > 0.62
    ? { right: `${(1 - fx) * 100}%`, top: `${fy * 100}%`, marginRight: '14px' }
    : { left: `${fx * 100}%`, top: `${fy * 100}%`, marginLeft: '14px' }
})
</script>
