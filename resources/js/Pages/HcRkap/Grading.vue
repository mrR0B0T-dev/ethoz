<template>
  <HcLayout title="Grading & Struktur Upah">
    <!-- ── stat tiles ─────────────────────────────────────────────────── -->
    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
      <StatCard label="Pegawai Aktif" :value="String(stats.total)" hint="seluruh status" />
      <StatCard label="Sudah Ter-grading" :value="String(stats.graded)" :hint="stats.ungraded ? `${stats.ungraded} belum` : 'semua ter-grading'" />
      <StatCard label="Rata-rata Compa-Ratio" :value="stats.avg_compa !== null ? String(stats.avg_compa) : '–'" hint="gaji ÷ mid struktur (ideal ≈ 1)" />
      <StatCard label="Di Bawah Min" :value="String(stats.below_min)" hint="gaji < min rentang grade-nya" />
      <StatCard label="Di Atas Max" :value="String(stats.above_max)" hint="gaji > max rentang grade-nya" />
    </div>

    <!-- ── widget keputusan ───────────────────────────────────────────── -->
    <div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-[1fr_20rem]">
      <div class="hc-card p-4">
        <h2 class="mb-1 text-sm font-semibold text-gray-900">Sebaran Gaji Dasar vs Struktur Upah</h2>
        <p class="mb-3 text-xs text-gray-500">
          Setiap titik = satu pegawai pada grade hasil grading. Area biru = rentang min–max struktur.
          Titik di luar garis putus-putus menandakan gaji di luar rentang dan perlu keputusan penyesuaian.
        </p>
        <ScatterChart :points="points" :bands="chartBands" />
      </div>

      <div class="hc-card p-4">
        <h2 class="mb-1 text-sm font-semibold text-gray-900">Headcount per Grade</h2>
        <p class="mb-3 text-xs text-gray-500">Distribusi pegawai hasil grading beserta rata-rata compa-ratio.</p>
        <div class="space-y-1.5">
          <div
            v-for="g in stats.per_grade" :key="g.code"
            class="grid grid-cols-[2.4rem_1fr_auto] items-center gap-2 text-xs"
            :title="`${g.code}: ${g.headcount} pegawai${g.avg_compa !== null ? `, compa ${g.avg_compa}` : ''}`"
          >
            <span class="font-mono text-gray-600">{{ g.code }}</span>
            <div class="relative h-3.5 overflow-hidden rounded-sm bg-gray-100">
              <div class="absolute inset-y-0 left-0 rounded-r-sm bg-[#2a78d6]" :style="{ width: headPct(g.headcount) + '%' }" />
            </div>
            <span class="w-24 text-right tabular-nums text-gray-700">
              {{ g.headcount }}<span v-if="g.avg_compa !== null" class="text-gray-400"> · c {{ g.avg_compa }}</span>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- ── struktur skala upah ────────────────────────────────────────── -->
    <div class="hc-card mb-5 overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
        <div>
          <h2 class="text-sm font-semibold text-gray-900">Struktur Skala Upah per Grade</h2>
          <p class="mt-0.5 text-xs text-gray-500">
            Rentang gaji dasar (min–mid–max), tunjangan jabatan &amp; transportasi tiap grade.
            Ubah nilai lalu simpan per baris; atur urutan dengan tombol panah — jalankan ulang grading otomatis setelah struktur berubah.
          </p>
        </div>
        <button class="hc-btn" @click="modalGrade = true">
          <PlusIcon class="h-4 w-4" /> Tambah Grade
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-max divide-y divide-gray-100 text-sm">
          <thead class="bg-gray-50/60">
            <tr>
              <th class="hc-th w-16 text-center">Urut</th>
              <th class="hc-th">Jabatan</th>
              <th class="hc-th">Grade</th>
              <th class="hc-th text-right">Level</th>
              <th class="hc-th text-right">Gaji Min</th>
              <th class="hc-th text-right">Gaji Mid</th>
              <th class="hc-th text-right">Gaji Max</th>
              <th class="hc-th text-right">Tunj. Jabatan</th>
              <th class="hc-th text-right">Tunj. Transportasi</th>
              <th class="hc-th w-20 text-center">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="(g, gi) in structure" :key="g.id" class="hover:bg-gray-50/40">
              <td class="px-2 py-1 text-center whitespace-nowrap">
                <button
                  class="rounded p-0.5 text-gray-400 hover:bg-blue-50 hover:text-blue-600 disabled:opacity-30"
                  :disabled="gi === 0" title="Geser ke atas" @click="moveRow(g, 'naik')"
                ><ChevronUpIcon class="h-4 w-4" /></button>
                <button
                  class="rounded p-0.5 text-gray-400 hover:bg-blue-50 hover:text-blue-600 disabled:opacity-30"
                  :disabled="gi === structure.length - 1" title="Geser ke bawah" @click="moveRow(g, 'turun')"
                ><ChevronDownIcon class="h-4 w-4" /></button>
              </td>
              <td class="px-2 py-1">
                <input v-model="g.jabatan" type="text" maxlength="40" class="hc-input w-36 py-1 text-xs" />
              </td>
              <td class="px-2 py-1">
                <input v-model="g.code" type="text" maxlength="8" class="hc-input w-16 py-1 font-mono text-xs font-semibold text-[#1c5cab]" />
              </td>
              <td class="px-2 py-1 text-right">
                <input v-model.number="g.level" type="number" min="1" max="99" class="hc-input w-16 py-1 text-right text-xs tabular-nums" />
              </td>
              <td v-for="f in FIELDS" :key="f" class="px-2 py-1 text-right">
                <HcNumberInput v-model="g[f]" class="hc-input w-28 py-1 text-right text-xs tabular-nums" />
              </td>
              <td class="px-2 py-1 text-center whitespace-nowrap">
                <button
                  class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600"
                  :disabled="savingGrade === g.id" title="Simpan baris ini"
                  @click="saveStructure(g)"
                >
                  <CheckIcon class="h-4 w-4" />
                </button>
                <button
                  class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                  title="Hapus grade ini"
                  @click="deleteStructure(g)"
                >
                  <TrashIcon class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!structure.length" class="p-8 text-center text-sm text-gray-400">
          Belum ada grade. Tambahkan lewat tombol "Tambah Grade".
        </p>
      </div>
    </div>

    <!-- modal tambah grade -->
    <HcModal :show="modalGrade" title="Tambah Grade Baru" @close="modalGrade = false">
      <form class="space-y-3" @submit.prevent="submitGrade">
        <div class="grid grid-cols-3 gap-3">
          <label class="col-span-1 block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Jabatan</span>
            <input v-model="gradeForm.jabatan" type="text" maxlength="40" required class="hc-input w-full" placeholder="mis. Staff" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Kode Grade</span>
            <input v-model="gradeForm.code" type="text" maxlength="8" required class="hc-input w-full font-mono" placeholder="mis. B-5" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Level</span>
            <input v-model.number="gradeForm.level" type="number" min="1" max="99" required class="hc-input w-full text-right" />
          </label>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Min</span>
            <HcNumberInput v-model="gradeForm.salary_min" required class="hc-input w-full text-right" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Mid</span>
            <HcNumberInput v-model="gradeForm.salary_mid" required class="hc-input w-full text-right" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Max</span>
            <HcNumberInput v-model="gradeForm.salary_max" required class="hc-input w-full text-right" />
          </label>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Jabatan</span>
            <HcNumberInput v-model="gradeForm.position_allowance" required class="hc-input w-full text-right" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Transportasi</span>
            <HcNumberInput v-model="gradeForm.transport_allowance" required class="hc-input w-full text-right" />
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="modalGrade = false">Batal</button>
          <button type="submit" class="hc-btn">Tambah</button>
        </div>
      </form>
    </HcModal>

    <!-- ── grading pegawai ────────────────────────────────────────────── -->
    <div class="hc-card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
        <div>
          <h2 class="text-sm font-semibold text-gray-900">Penetapan Grade Pegawai</h2>
          <p class="mt-0.5 text-xs text-gray-500">
            Grade <b>auto</b> disimpulkan dari tunjangan jabatan / rentang gaji; ubah lewat dropdown untuk menetapkan manual.
          </p>
        </div>
        <button class="hc-btn" :disabled="applying" @click="applyAll">
          <BoltIcon class="h-4 w-4" /> {{ applying ? 'Menerapkan…' : 'Terapkan Grading Otomatis' }}
        </button>
      </div>

      <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3">
        <div class="relative">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <input v-model="search" type="search" class="hc-input w-56 py-1.5 pl-8" placeholder="Cari nama / unit…" />
        </div>
        <select v-model="filterGrade" class="hc-select">
          <option :value="null">Semua grade</option>
          <option v-for="g in grades" :key="g.id" :value="g.id">{{ g.code }} — {{ g.jabatan }}</option>
        </select>
        <select v-model="filterBand" class="hc-select">
          <option :value="null">Semua posisi rentang</option>
          <option value="dalam">Dalam rentang</option>
          <option value="bawah">Di bawah min</option>
          <option value="atas">Di atas max</option>
        </select>
        <p class="ml-auto text-xs text-gray-400">{{ filteredEmployees.length }} dari {{ employees.length }} pegawai</p>
      </div>

      <div class="max-h-[34rem] overflow-y-auto">
        <table class="min-w-full divide-y divide-gray-100 text-sm">
          <thead class="sticky top-0 z-10 bg-gray-50">
            <tr>
              <th class="hc-th">Nama</th>
              <th class="hc-th">Jabatan</th>
              <th class="hc-th">Unit</th>
              <th class="hc-th">Status</th>
              <th class="hc-th text-right">Gaji Dasar /bln</th>
              <th class="hc-th text-right">Tunj. Jabatan</th>
              <th class="hc-th w-40">Grade / Level</th>
              <th class="hc-th">Sumber</th>
              <th class="hc-th text-right">Compa</th>
              <th class="hc-th">Posisi Rentang</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="e in filteredEmployees" :key="e.id" class="hover:bg-gray-50/50">
              <td class="hc-td font-medium text-gray-900">{{ e.name }}</td>
              <td class="hc-td text-xs text-gray-500" :title="e.jabatan">{{ e.jabatan ?? '–' }}</td>
              <td class="hc-td text-xs text-gray-500">{{ e.unit ?? '–' }}</td>
              <td class="hc-td text-xs text-gray-500">{{ e.status }}</td>
              <td class="hc-td text-right tabular-nums">{{ fmtNum(e.base_salary) }}</td>
              <td class="hc-td text-right tabular-nums text-gray-500">{{ e.position_allowance ? fmtNum(e.position_allowance) : '–' }}</td>
              <td class="px-3 py-1">
                <select
                  class="hc-select w-36 py-1 text-xs"
                  :value="e.salary_grade_id"
                  @change="assign(e, $event.target.value)"
                >
                  <option :value="null">— tanpa grade —</option>
                  <option v-for="g in grades" :key="g.id" :value="g.id">{{ g.code }} · lvl {{ g.level }}</option>
                </select>
              </td>
              <td class="hc-td">
                <span
                  v-if="e.grade_source"
                  class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                  :class="e.grade_source === 'manual' ? 'bg-purple-50 text-purple-700' : 'bg-gray-100 text-gray-500'"
                >{{ e.grade_source }}</span>
              </td>
              <td class="hc-td text-right tabular-nums" :class="compaClass(e.compa)">{{ e.compa ?? '–' }}</td>
              <td class="hc-td">
                <span
                  v-if="e.band"
                  class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                  :class="BAND_BADGE[e.band].class"
                >
                  <component :is="BAND_BADGE[e.band].icon" class="h-3 w-3" />
                  {{ BAND_BADGE[e.band].label }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!filteredEmployees.length" class="p-8 text-center text-sm text-gray-400">
          Tidak ada pegawai yang cocok dengan pencarian/filter.
        </p>
      </div>
    </div>
  </HcLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  BoltIcon, CheckIcon, CheckCircleIcon, ChevronDownIcon, ChevronUpIcon,
  ExclamationTriangleIcon, FireIcon, MagnifyingGlassIcon, PlusIcon, TrashIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import StatCard from '@/Components/HcRkap/StatCard.vue'
import ScatterChart from '@/Components/HcRkap/ScatterChart.vue'
import HcNumberInput from '@/Components/HcRkap/HcNumberInput.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  grades: Array,
  employees: Array,
  points: Array,
  stats: Object,
})

const { fmtNum } = useHcFormat()

const FIELDS = ['salary_min', 'salary_mid', 'salary_max', 'position_allowance', 'transport_allowance']

const BAND_BADGE = {
  dalam: { label: 'Dalam rentang', class: 'bg-blue-50 text-blue-700', icon: CheckCircleIcon },
  bawah: { label: 'Di bawah min', class: 'bg-amber-50 text-amber-700', icon: ExclamationTriangleIcon },
  atas: { label: 'Di atas max', class: 'bg-red-50 text-red-700', icon: FireIcon },
}

const compaClass = (c) => {
  if (c === null || c === undefined) return 'text-gray-400'
  if (c < 0.8 || c > 1.2) return 'font-medium text-amber-700'
  return 'text-gray-600'
}

const chartBands = computed(() => props.grades.map(g => ({
  level: g.level,
  code: g.code,
  min: g.salary_min,
  mid: g.salary_mid,
  max: g.salary_max,
})))

const maxHead = computed(() => Math.max(...props.stats.per_grade.map(g => g.headcount), 1))
const headPct = (n) => Math.max(n / maxHead.value * 100, n ? 2 : 0)

// ── struktur upah (salinan lokal, simpan per baris) ───────────────────────
const structure = reactive(props.grades.map(g => ({ ...g })))
const savingGrade = ref(null)
const modalGrade = ref(false)

// selaraskan salinan lokal saat data server berubah (tambah/hapus/geser)
watch(() => props.grades, (next) => {
  structure.splice(0, structure.length, ...next.map(g => ({ ...g })))
})

function saveStructure(g) {
  savingGrade.value = g.id
  router.put(route('hc.grading.struktur', g.id), {
    jabatan: g.jabatan,
    code: g.code,
    level: g.level,
    salary_min: g.salary_min,
    salary_mid: g.salary_mid,
    salary_max: g.salary_max,
    position_allowance: g.position_allowance,
    transport_allowance: g.transport_allowance,
    tahun: props.tahun.year,
  }, {
    preserveScroll: true,
    onFinish: () => { savingGrade.value = null },
  })
}

function moveRow(g, arah) {
  router.put(route('hc.grading.struktur.urutan', g.id), {
    arah,
    tahun: props.tahun.year,
  }, { preserveScroll: true })
}

function deleteStructure(g) {
  Swal.fire({
    title: `Hapus grade ${g.code}?`,
    text: 'Pegawai pada grade ini akan dilepas grade-nya dan perlu di-grading ulang.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  }).then((res) => {
    if (!res.isConfirmed) return
    router.delete(route('hc.grading.struktur.destroy', g.id), {
      data: { tahun: props.tahun.year },
      preserveScroll: true,
    })
  })
}

const gradeForm = reactive({
  jabatan: '', code: '', level: null,
  salary_min: null, salary_mid: null, salary_max: null,
  position_allowance: null, transport_allowance: null,
})

function submitGrade() {
  router.post(route('hc.grading.struktur.store'), { ...gradeForm, tahun: props.tahun.year }, {
    preserveScroll: true,
    onSuccess: () => {
      modalGrade.value = false
      Object.assign(gradeForm, {
        jabatan: '', code: '', level: null,
        salary_min: null, salary_mid: null, salary_max: null,
        position_allowance: null, transport_allowance: null,
      })
    },
  })
}

// ── grading pegawai ───────────────────────────────────────────────────────
const search = ref('')
const filterGrade = ref(null)
const filterBand = ref(null)
const applying = ref(false)

const filteredEmployees = computed(() => {
  let list = props.employees
  const q = search.value.trim().toLowerCase()
  if (q) list = list.filter(e => e.name.toLowerCase().includes(q) || (e.jabatan ?? '').toLowerCase().includes(q) || (e.unit ?? '').toLowerCase().includes(q))
  if (filterGrade.value) list = list.filter(e => e.salary_grade_id === filterGrade.value)
  if (filterBand.value) list = list.filter(e => e.band === filterBand.value)
  return list
})

function assign(e, value) {
  router.put(route('hc.grading.pegawai', e.id), {
    salary_grade_id: value || null,
    tahun: props.tahun.year,
  }, { preserveScroll: true })
}

function applyAll() {
  Swal.fire({
    title: 'Terapkan grading otomatis?',
    html: 'Grade seluruh pegawai aktif akan disimpulkan dari <b>tunjangan jabatan</b> / <b>rentang gaji</b> struktur upah.',
    icon: 'question',
    showCancelButton: true,
    showDenyButton: true,
    confirmButtonColor: '#2a78d6',
    denyButtonColor: '#8a3ab5',
    confirmButtonText: 'Terapkan (pertahankan manual)',
    denyButtonText: 'Terapkan & timpa manual',
    cancelButtonText: 'Batal',
  }).then((res) => {
    if (!res.isConfirmed && !res.isDenied) return
    applying.value = true
    router.post(route('hc.grading.terapkan'), {
      overwrite_manual: res.isDenied,
      tahun: props.tahun.year,
    }, {
      preserveScroll: true,
      onFinish: () => { applying.value = false },
    })
  })
}
</script>
