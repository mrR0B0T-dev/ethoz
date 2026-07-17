<template>
  <!-- Tren bulanan: batang masuk/keluar (skala kiri) + garis headcount (skala kanan) -->
  <div class="overflow-x-auto">
    <svg :viewBox="`0 0 ${W} ${H}`" class="min-w-[560px]" role="img" aria-label="Grafik tren turnover bulanan">
      <!-- grid & sumbu kiri (jumlah kejadian) -->
      <g v-for="tick in barTicks" :key="`t${tick}`">
        <line :x1="P.l" :x2="W - P.r" :y1="yBar(tick)" :y2="yBar(tick)" stroke="#f1f3f5" stroke-width="1" />
        <text :x="P.l - 6" :y="yBar(tick) + 3" text-anchor="end" class="fill-gray-400 text-[9px]">{{ tick }}</text>
      </g>

      <!-- batang masuk & keluar -->
      <g v-for="(m, i) in monthly" :key="m.bulan">
        <rect
          :x="xMonth(i) + colW * 0.16" :y="yBar(m.masuk)"
          :width="colW * 0.26" :height="baseY - yBar(m.masuk)"
          fill="#10b981" rx="1.5"
        ><title>{{ MONTHS[i] }}: {{ m.masuk }} masuk</title></rect>
        <rect
          :x="xMonth(i) + colW * 0.5" :y="yBar(m.keluar)"
          :width="colW * 0.26" :height="baseY - yBar(m.keluar)"
          fill="#ef4444" rx="1.5"
        ><title>{{ MONTHS[i] }}: {{ m.keluar }} keluar</title></rect>
        <text :x="xMonth(i) + colW / 2" :y="H - 6" text-anchor="middle" class="fill-gray-500 text-[9px]">{{ MONTHS[i] }}</text>
      </g>

      <!-- garis headcount -->
      <polyline :points="hcPoints" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" />
      <g v-for="(m, i) in monthly" :key="`hc${m.bulan}`">
        <circle
          v-if="m.headcount !== null"
          :cx="xMonth(i) + colW / 2" :cy="yHc(m.headcount)" r="3"
          fill="#fff" stroke="#2a78d6" stroke-width="2"
        ><title>{{ MONTHS[i] }}: {{ m.headcount }} pegawai aktif</title></circle>
      </g>

      <!-- sumbu kanan (headcount) -->
      <text :x="W - P.r + 6" :y="yHc(hcMax) + 3" class="fill-[#2a78d6] text-[9px] font-medium">{{ hcMax }}</text>
      <text :x="W - P.r + 6" :y="yHc(hcMin) + 3" class="fill-[#2a78d6] text-[9px] font-medium">{{ hcMin }}</text>

      <line :x1="P.l" :x2="W - P.r" :y1="baseY" :y2="baseY" stroke="#d1d5db" stroke-width="1" />
    </svg>

    <div class="mt-1 flex flex-wrap items-center gap-4 px-1 text-[11px] text-gray-500">
      <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500" /> Masuk</span>
      <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-red-500" /> Keluar</span>
      <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-[#2a78d6]" /> Headcount akhir bulan</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({ monthly: { type: Array, required: true } })

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
const W = 760
const H = 230
const P = { t: 12, r: 44, b: 22, l: 34 }
const baseY = H - P.b
const colW = (W - P.l - P.r) / 12
const xMonth = (i) => P.l + i * colW

// skala batang (kejadian)
const barMax = computed(() => Math.max(1, ...props.monthly.map(m => Math.max(m.masuk, m.keluar))))
const yBar = (v) => baseY - (v / barMax.value) * (baseY - P.t)
const barTicks = computed(() => {
  const step = Math.max(1, Math.ceil(barMax.value / 4))
  const out = []
  for (let t = 0; t <= barMax.value; t += step) out.push(t)
  return out
})

// skala garis headcount (rentang di-pad agar variasi kecil tetap terlihat)
const hcVals = computed(() => props.monthly.filter(m => m.headcount !== null).map(m => m.headcount))
const hcMin = computed(() => hcVals.value.length ? Math.min(...hcVals.value) : 0)
const hcMax = computed(() => hcVals.value.length ? Math.max(...hcVals.value) : 0)
const yHc = (v) => {
  const span = Math.max(hcMax.value - hcMin.value, 1)
  return P.t + 14 + (1 - (v - hcMin.value) / span) * (baseY - P.t - 34)
}
const hcPoints = computed(() => props.monthly
  .map((m, i) => m.headcount === null ? null : `${xMonth(i) + colW / 2},${yHc(m.headcount)}`)
  .filter(Boolean).join(' '))
</script>
