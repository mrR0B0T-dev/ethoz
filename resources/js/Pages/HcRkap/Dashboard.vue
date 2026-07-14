<template>
  <HcLayout title="Dashboard RKAP HC">
    <!-- Filter (satu baris, di atas semua yang dipengaruhinya) -->
    <div class="hc-card mb-5 flex flex-wrap items-end gap-x-5 gap-y-3 p-4">
      <div class="flex items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Periode</span>
          <select v-model.number="form.bulan_awal" class="hc-select">
            <option v-for="(b, i) in BULAN_PANJANG" :key="i" :value="i + 1">{{ b }}</option>
          </select>
        </label>
        <span class="pb-2 text-xs text-gray-400">s.d.</span>
        <select v-model.number="form.bulan_akhir" class="hc-select">
          <option v-for="(b, i) in BULAN_PANJANG" :key="i" :value="i + 1">{{ b }}</option>
        </select>
      </div>

      <label class="block">
        <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Jenis Biaya</span>
        <select v-model="form.jenis_biaya" class="hc-select max-w-56">
          <option :value="null">Semua jenis biaya</option>
          <optgroup v-for="root in costTypeTree" :key="root.id" :label="root.name">
            <option :value="root.id">{{ root.name }} (semua)</option>
            <option v-for="child in root.children" :key="child.id" :value="child.id">— {{ child.name }}</option>
          </optgroup>
        </select>
      </label>

      <UnitCascade v-model="form.unit" :units="options.units" />

      <button v-if="isFiltered" class="hc-btn-secondary ml-auto" @click="resetFilters">
        <ArrowPathIcon class="h-4 w-4" /> Reset
      </button>
    </div>

    <!-- ══ ZONA UTAMA (paling penting, dilihat pertama) ══ -->
    <div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-3">
      <!-- Sinyal keputusan: serapan + proyeksi + KPI pendukung -->
      <div class="space-y-4 xl:col-span-2">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <!-- Serapan berjalan -->
          <div class="rounded-xl border p-5" :class="[serapanTone.border, serapanTone.bg]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                Serapan Anggaran<br />s.d. {{ lastRealLabel }}
              </p>
              <span class="inline-flex items-center gap-1 rounded-full bg-white/70 px-2 py-0.5 text-[11px] font-semibold" :class="serapanTone.text">
                <component :is="serapanTone.icon" class="h-3.5 w-3.5" /> {{ serapanTone.label }}
              </span>
            </div>
            <p class="mt-2 text-4xl font-bold leading-none" :class="serapanTone.text">
              {{ kpi.serapan_ytd !== null ? fmtPct(kpi.serapan_ytd) : '—' }}
            </p>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/60">
              <div class="h-full rounded-full" :style="{ width: Math.min(kpi.serapan_ytd || 0, 100) + '%', background: serapanTone.bar }" />
            </div>
            <p class="mt-2.5 text-xs text-gray-600">
              <template v-if="kpi.serapan_ytd !== null">
                Realisasi <b class="text-gray-900">{{ fmtShort(kpi.realisasi) }}</b>
                dari pagu berjalan <b class="text-gray-900">{{ fmtShort(kpi.rkap_ytd) }}</b>.
              </template>
              <template v-else>Belum ada realisasi tercatat pada periode ini.</template>
            </p>
            <p class="mt-1.5 flex items-start gap-1 text-xs font-medium" :class="serapanTone.text">
              <component :is="serapanTone.icon" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <span>{{ serapanAction }}</span>
            </p>
          </div>

          <!-- Proyeksi akhir tahun -->
          <div class="rounded-xl border p-5" :class="[proyeksiTone.border, proyeksiTone.bg]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                Proyeksi Belanja<br />Akhir Tahun {{ tahun.year }}
              </p>
              <span class="inline-flex items-center gap-1 rounded-full bg-white/70 px-2 py-0.5 text-[11px] font-semibold" :class="proyeksiTone.text">
                <component :is="proyeksiTone.icon" class="h-3.5 w-3.5" /> {{ proyeksiTone.label }}
              </span>
            </div>
            <p class="mt-2 text-4xl font-bold leading-none" :class="proyeksiTone.text">
              {{ projection ? fmtShort(projection.projected) : '—' }}
            </p>
            <p v-if="projection" class="mt-3 text-xs text-gray-600">
              vs RKAP setahun <b class="text-gray-900">{{ fmtShort(projection.annualRkap) }}</b>
              <span :class="proyeksiTone.text" class="font-semibold">
                ({{ projection.diff >= 0 ? '+' : '' }}{{ fmtShort(projection.diff) }})
              </span>
            </p>
            <p v-else class="mt-3 text-xs text-gray-500">Perlu minimal satu bulan realisasi untuk memproyeksikan.</p>
            <p class="mt-1.5 flex items-start gap-1 text-xs font-medium" :class="proyeksiTone.text">
              <component :is="proyeksiTone.icon" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <span>{{ proyeksiAction }}</span>
            </p>
          </div>
        </div>

        <!-- KPI pendukung (diperkecil) -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-lg border border-gray-200 bg-white p-3">
            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">RKAP · {{ periodeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ fmtShort(kpi.rkap) }}</p>
          </div>
          <div class="rounded-lg border border-gray-200 bg-white p-3">
            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">Realisasi</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ kpi.realisasi ? fmtShort(kpi.realisasi) : '–' }}</p>
          </div>
          <div class="rounded-lg border border-gray-200 bg-white p-3">
            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">Sisa Pagu</p>
            <p class="mt-1 text-lg font-semibold" :class="(kpi.rkap - kpi.realisasi) < 0 ? 'text-red-600' : 'text-gray-900'">
              {{ fmtShort(kpi.rkap - kpi.realisasi) }}
            </p>
          </div>
          <div class="rounded-lg border border-gray-200 bg-white p-3">
            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">YoY {{ kpi.prev_year ?? '' }}</p>
            <p class="mt-1 flex items-center gap-1 text-lg font-semibold" :class="yoyClass(kpi.yoy)">
              <component :is="kpi.yoy > 0 ? ArrowTrendingUpIcon : kpi.yoy < 0 ? ArrowTrendingDownIcon : MinusIcon" v-if="kpi.yoy !== null" class="h-4 w-4" />
              {{ kpi.yoy !== null ? fmtPct(kpi.yoy, true) : '–' }}
            </p>
          </div>
        </div>
      </div>

      <!-- Panel tindakan: Perlu Perhatian -->
      <div class="hc-card flex flex-col p-4">
        <div class="mb-1 flex items-center gap-2">
          <BellAlertIcon class="h-4 w-4 text-[#d03b3b]" />
          <h2 class="text-sm font-semibold text-gray-900">Perlu Perhatian</h2>
        </div>
        <p class="mb-3 text-[11px] text-gray-500">Prioritas tindakan untuk periode terpilih.</p>

        <ul v-if="attention.length" class="flex-1 divide-y divide-gray-50">
          <li v-for="(a, i) in attention" :key="i" class="flex items-start gap-2.5 py-2.5">
            <component :is="a.icon" class="mt-0.5 h-4 w-4 shrink-0" :class="a.iconClass" />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-gray-900">{{ a.name }}</p>
              <p class="text-xs text-gray-500">{{ a.detail }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="a.badgeClass">{{ a.badge }}</span>
          </li>
        </ul>

        <div v-else class="flex flex-1 flex-col items-center justify-center py-8 text-center">
          <component :is="emptyAttention.icon" class="mb-2 h-8 w-8" :class="emptyAttention.iconClass" />
          <p class="text-sm font-medium text-gray-700">{{ emptyAttention.title }}</p>
          <p class="mt-1 max-w-52 text-xs text-gray-500">{{ emptyAttention.text }}</p>
        </div>
      </div>
    </div>

    <!-- ══ TREN & KOMPOSISI (pendukung) ══ -->
    <div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-3">
      <div class="hc-card p-4 xl:col-span-2">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Tren Bulanan {{ tahun.year }} — RKAP vs Realisasi</h2>
        <BarLineChart
          :labels="BULAN"
          :bars="{ name: `RKAP ${tahun.year}`, values: data.monthly.map(m => m.rkap), color: '#2a78d6' }"
          :lines="trendLines"
          aria-label="Tren anggaran bulanan"
        />
      </div>
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Komposisi Jenis Biaya</h2>
        <DonutChart :items="donutItems" aria-label="Komposisi jenis biaya" />
      </div>
    </div>

    <!-- ══ PER UNIT & DETAIL (referensi) ══ -->
    <div class="mb-5 hc-card p-4">
      <h2 class="mb-3 text-sm font-semibold text-gray-900">Anggaran per Unit Kerja</h2>
      <HBarChart :items="unitItems" />
      <p v-if="!unitItems.length" class="py-6 text-center text-sm text-gray-400">Tidak ada data untuk filter ini.</p>
    </div>

    <!-- Tabel ringkasan kategori (referensi rinci) -->
    <div class="hc-card overflow-x-auto">
      <div class="border-b border-gray-100 px-4 py-3">
        <h2 class="text-sm font-semibold text-gray-900">Rincian per Kategori Biaya</h2>
      </div>
      <table class="min-w-full divide-y divide-gray-100">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th">Jenis Biaya</th>
            <th class="hc-th text-right">RKAP {{ tahun.year }}</th>
            <th class="hc-th text-right">Porsi</th>
            <th class="hc-th text-right">Realisasi</th>
            <th class="hc-th text-right">Serapan</th>
            <th class="hc-th text-right">RKAP {{ kpi.prev_year ?? 'Th. Lalu' }}</th>
            <th class="hc-th text-right">YoY</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr v-for="cat in data.by_category" :key="cat.id" class="hover:bg-gray-50/50">
            <td class="hc-td font-medium text-gray-900">
              <span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: colorFor(cat.code) }" />
              {{ cat.name }}
            </td>
            <td class="hc-td text-right tabular-nums">{{ fmtIDR(cat.rkap) }}</td>
            <td class="hc-td text-right tabular-nums text-gray-500">{{ fmtPct(cat.share) }}</td>
            <td class="hc-td text-right tabular-nums">{{ cat.realisasi ? fmtIDR(cat.realisasi) : '–' }}</td>
            <td class="hc-td text-right tabular-nums">
              <span v-if="cat.serapan_ytd !== null" :class="serapanTextClass(cat.serapan_ytd)">{{ fmtPct(cat.serapan_ytd) }}</span>
              <span v-else class="text-gray-400">–</span>
            </td>
            <td class="hc-td text-right tabular-nums text-gray-500">{{ cat.prev_rkap ? fmtIDR(cat.prev_rkap) : '–' }}</td>
            <td class="hc-td text-right tabular-nums" :class="yoyClass(cat.yoy)">{{ cat.yoy !== null ? fmtPct(cat.yoy, true) : '–' }}</td>
          </tr>
        </tbody>
        <tfoot class="border-t border-gray-200 bg-gray-50/60">
          <tr>
            <td class="hc-td font-semibold text-gray-900">Total Biaya Personil</td>
            <td class="hc-td text-right font-semibold tabular-nums text-gray-900">{{ fmtIDR(kpi.rkap) }}</td>
            <td class="hc-td text-right tabular-nums text-gray-500">100%</td>
            <td class="hc-td text-right font-semibold tabular-nums">{{ kpi.realisasi ? fmtIDR(kpi.realisasi) : '–' }}</td>
            <td class="hc-td text-right tabular-nums">
              <span v-if="kpi.serapan_ytd !== null" :class="serapanTextClass(kpi.serapan_ytd)">{{ fmtPct(kpi.serapan_ytd) }}</span>
              <span v-else class="text-gray-400">–</span>
            </td>
            <td class="hc-td text-right font-semibold tabular-nums text-gray-500">{{ kpi.prev_rkap ? fmtIDR(kpi.prev_rkap) : '–' }}</td>
            <td class="hc-td text-right font-semibold tabular-nums" :class="yoyClass(kpi.yoy)">{{ kpi.yoy !== null ? fmtPct(kpi.yoy, true) : '–' }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </HcLayout>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import {
  ArrowPathIcon, ArrowTrendingUpIcon, ArrowTrendingDownIcon, MinusIcon,
  BellAlertIcon, ExclamationTriangleIcon, FireIcon, CheckCircleIcon,
  BanknotesIcon, ClockIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import BarLineChart from '@/Components/HcRkap/BarLineChart.vue'
import DonutChart from '@/Components/HcRkap/DonutChart.vue'
import HBarChart from '@/Components/HcRkap/HBarChart.vue'
import UnitCascade from '@/Components/HcRkap/UnitCascade.vue'
import { useHcFormat, BULAN, BULAN_PANJANG } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  filters: Object,
  options: Object,
  data: Object,
})

const { fmtIDR, fmtShort, fmtPct } = useHcFormat()
const kpi = computed(() => props.data.kpi)

// warna kategorikal tetap per kode kategori (bukan per urutan)
const CATEGORY_COLORS = {
  GAJI: '#2a78d6', TUNJ: '#1baf7a', HONOR: '#eda100',
  IURAN: '#008300', LEMBUR: '#4a3aa7', LAIN: '#e34948',
}
const FALLBACKS = ['#e87ba4', '#eb6834']
const colorFor = (code) => CATEGORY_COLORS[code]
  ?? FALLBACKS[Math.abs([...code].reduce((a, c) => a + c.charCodeAt(0), 0)) % FALLBACKS.length]

// ── filter ────────────────────────────────────────────────────────────────
const form = reactive({
  bulan_awal: props.filters.month_start ?? 1,
  bulan_akhir: props.filters.month_end ?? 12,
  jenis_biaya: props.filters.cost_type_id ?? null,
  unit: props.filters.unit_id ?? null,
})

watch(form, () => {
  if (form.bulan_akhir < form.bulan_awal) form.bulan_akhir = form.bulan_awal
  router.get(route('hc.dashboard'), {
    tahun: props.tahun.year,
    bulan_awal: form.bulan_awal,
    bulan_akhir: form.bulan_akhir,
    jenis_biaya: form.jenis_biaya ?? undefined,
    unit: form.unit ?? undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
})

const isFiltered = computed(() =>
  form.bulan_awal !== 1 || form.bulan_akhir !== 12 || form.jenis_biaya || form.unit)

function resetFilters() {
  Object.assign(form, { bulan_awal: 1, bulan_akhir: 12, jenis_biaya: null, unit: null })
}

const costTypeTree = computed(() => props.options.costTypes
  .filter(t => !t.parent_id)
  .map(root => ({
    ...root,
    children: props.options.costTypes.filter(t => t.parent_id === root.id),
  })))

// ── label periode ─────────────────────────────────────────────────────────
const jumlahBulan = computed(() => form.bulan_akhir - form.bulan_awal + 1)
const periodeLabel = computed(() => jumlahBulan.value === 12
  ? 'Setahun'
  : `${BULAN[form.bulan_awal - 1]}–${BULAN[form.bulan_akhir - 1]}`)
const lastRealLabel = computed(() => kpi.value.last_real_month
  ? `${BULAN[kpi.value.last_real_month - 1]} ${props.tahun.year}`
  : `${props.tahun.year}`)

// ── nada status (warna = penekanan, bukan hiasan) ─────────────────────────
const TONES = {
  good: { bg: 'bg-emerald-50', border: 'border-emerald-200', text: 'text-emerald-700', bar: '#0ca30c', icon: CheckCircleIcon, label: 'Terkendali' },
  warning: { bg: 'bg-amber-50', border: 'border-amber-200', text: 'text-amber-700', bar: '#e0920c', icon: ExclamationTriangleIcon, label: 'Waspada' },
  critical: { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', bar: '#d03b3b', icon: FireIcon, label: 'Over Budget' },
  neutral: { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-600', bar: '#9ca3af', icon: ClockIcon, label: 'Menunggu Data' },
}

// Serapan berjalan: realisasi vs pagu periode yang sudah berjalan
const serapanTone = computed(() => {
  const s = kpi.value.serapan_ytd
  if (s === null) return TONES.neutral
  if (s > 105) return TONES.critical
  if (s > 100) return TONES.warning
  return TONES.good
})
const serapanAction = computed(() => {
  const s = kpi.value.serapan_ytd
  if (s === null) return 'Input realisasi via menu Realisasi & Monitoring untuk mulai memantau.'
  if (s > 105) return `Belanja ${fmtPct(s - 100)} di atas rencana periode ini — evaluasi kategori over-budget di samping.`
  if (s > 100) return 'Belanja sedikit melewati pagu berjalan — pantau kategori serapan tinggi.'
  return 'Belanja masih dalam pagu periode berjalan.'
})

// Proyeksi akhir tahun dari run-rate realisasi (sinyal ke depan)
const projection = computed(() => {
  const withReal = props.data.monthly.filter(m => m.realisasi > 0)
  if (!withReal.length) return null
  const elapsed = Math.max(...withReal.map(m => m.month))
  const realToDate = props.data.monthly.filter(m => m.month <= elapsed).reduce((s, m) => s + m.realisasi, 0)
  const annualRkap = props.data.monthly.reduce((s, m) => s + m.rkap, 0)
  const projected = realToDate / elapsed * 12
  return {
    elapsed,
    projected,
    annualRkap,
    diff: projected - annualRkap,
    pct: annualRkap > 0 ? projected / annualRkap * 100 : null,
  }
})
const proyeksiTone = computed(() => {
  if (!projection.value || projection.value.pct === null) return TONES.neutral
  const p = projection.value.pct
  if (p > 105) return TONES.critical
  if (p > 100) return TONES.warning
  return TONES.good
})
const proyeksiAction = computed(() => {
  if (!projection.value) return 'Belum dapat diproyeksikan.'
  const { pct, diff } = projection.value
  if (pct > 100) return `Tren belanja mengarah ${fmtShort(diff)} di atas RKAP — siapkan langkah efisiensi.`
  return `Tren belanja mengarah ${fmtShort(Math.abs(diff))} di bawah RKAP — anggaran aman.`
})

// ── panel Perlu Perhatian ─────────────────────────────────────────────────
const SEV = {
  critical: { rank: 0, icon: FireIcon, iconClass: 'text-[#d03b3b]', badgeClass: 'bg-red-50 text-red-700' },
  warning: { rank: 1, icon: ExclamationTriangleIcon, iconClass: 'text-[#e0920c]', badgeClass: 'bg-amber-50 text-amber-700' },
  info: { rank: 2, icon: ArrowTrendingUpIcon, iconClass: 'text-[#4a3aa7]', badgeClass: 'bg-violet-50 text-violet-700' },
}
const attention = computed(() => {
  const items = []
  props.data.by_category.forEach((c) => {
    if (c.serapan_ytd !== null && c.serapan_ytd > 100) {
      const sev = c.serapan_ytd > 110 ? 'critical' : 'warning'
      items.push({
        sev, name: c.name,
        detail: `Serapan ${fmtPct(c.serapan_ytd)} · ${fmtShort(c.realisasi - c.rkap_ytd)} di atas pagu berjalan`,
        badge: 'Over', magnitude: c.realisasi - c.rkap_ytd,
      })
    }
  })
  props.data.by_category.forEach((c) => {
    if (c.yoy !== null && c.yoy > 15) {
      items.push({
        sev: 'info', name: c.name,
        detail: `Naik ${fmtPct(c.yoy, true)} vs ${kpi.value.prev_year} — cek asumsi kenaikan`,
        badge: 'YoY', magnitude: c.yoy,
      })
    }
  })
  return items
    .map(a => ({ ...a, ...SEV[a.sev] }))
    .sort((x, y) => x.rank - y.rank || y.magnitude - x.magnitude)
    .slice(0, 6)
})
const emptyAttention = computed(() => {
  if (kpi.value.serapan_ytd === null) {
    return {
      icon: ClockIcon, iconClass: 'text-gray-300',
      title: 'Belum ada realisasi',
      text: 'Input realisasi bulanan untuk mengaktifkan pemantauan otomatis.',
    }
  }
  return {
    icon: CheckCircleIcon, iconClass: 'text-emerald-400',
    title: 'Semua kategori terkendali',
    text: 'Tidak ada kategori yang melewati pagu atau melonjak signifikan.',
  }
})

// ── grafik ────────────────────────────────────────────────────────────────
const hasRealisasi = computed(() => props.data.monthly.some(m => m.realisasi > 0))
const hasPrev = computed(() => props.data.monthly.some(m => m.prev_rkap > 0))

const trendLines = computed(() => {
  const lines = []
  if (hasRealisasi.value) {
    lines.push({ name: 'Realisasi', values: props.data.monthly.map(m => m.realisasi), color: '#1baf7a' })
  }
  if (hasPrev.value) {
    // tahun lalu = konteks → abu-abu, tidak menyaingi seri utama
    lines.push({ name: `RKAP ${kpi.value.prev_year}`, values: props.data.monthly.map(m => m.prev_rkap), color: '#9ca3af', dashed: true })
  }
  return lines
})

const donutItems = computed(() => props.data.by_category
  .filter(c => c.rkap > 0)
  .map(c => ({ label: c.name, value: c.rkap, color: colorFor(c.code) })))

const unitItems = computed(() => props.data.by_unit.map(u => ({
  label: u.code, name: u.name, value: u.rkap,
  secondary: u.realisasi > 0 ? u.realisasi : null,
})))

// ── kelas warna ───────────────────────────────────────────────────────────
const yoyClass = (yoy) => yoy === null ? 'text-gray-400' : yoy > 10 ? 'text-red-600' : yoy < 0 ? 'text-emerald-700' : 'text-gray-700'
const serapanTextClass = (s) => s > 105 ? 'font-semibold text-red-600' : s > 100 ? 'font-semibold text-amber-600' : 'text-emerald-700'
</script>
