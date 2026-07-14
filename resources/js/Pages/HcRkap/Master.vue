<template>
  <HcLayout title="Master Data">
    <!-- Tabs -->
    <div class="mb-5 flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-white p-1.5">
      <button
        v-for="t in TABS" :key="t.key"
        class="flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === t.key ? 'bg-[#2a78d6] text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="tab = t.key"
      >
        <component :is="t.icon" class="h-4 w-4" /> {{ t.label }}
      </button>
    </div>

    <!-- ── TAHUN ANGGARAN ─────────────────────────────────────────────────── -->
    <div v-if="tab === 'tahun'">
      <div class="mb-4 flex justify-end">
        <button class="hc-btn" @click="openYearModal">
          <PlusIcon class="h-4 w-4" /> Tahun Anggaran Baru
        </button>
      </div>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="y in years" :key="y.id" class="hc-card p-4">
          <div class="mb-2 flex items-center justify-between">
            <p class="text-lg font-bold text-gray-900">{{ y.label ?? y.year }}</p>
            <span
              class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase"
              :class="{
                'bg-emerald-50 text-emerald-700': y.status === 'aktif',
                'bg-amber-50 text-amber-700': y.status === 'draft',
                'bg-gray-100 text-gray-500': y.status === 'final',
              }"
            >{{ y.status }}</span>
          </div>
          <dl class="mb-3 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">Total RKAP</dt><dd class="font-medium tabular-nums">{{ y.rkap_total ? fmtShort(y.rkap_total) : '–' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Total Realisasi</dt><dd class="font-medium tabular-nums">{{ y.realisasi_total ? fmtShort(y.realisasi_total) : '–' }}</dd></div>
          </dl>
          <p v-if="y.notes" class="mb-3 line-clamp-2 text-xs text-gray-400" :title="y.notes">{{ y.notes }}</p>
          <div class="flex flex-wrap gap-2">
            <button v-if="y.status === 'draft'" class="hc-btn-secondary !px-2.5 !py-1.5 text-xs" @click="setStatus(y, 'aktif')">
              <PlayIcon class="h-3.5 w-3.5" /> Aktifkan
            </button>
            <button v-if="y.status === 'aktif'" class="hc-btn-secondary !px-2.5 !py-1.5 text-xs" @click="setStatus(y, 'final')">
              <LockClosedIcon class="h-3.5 w-3.5" /> Finalkan
            </button>
            <button v-if="y.status === 'final'" class="hc-btn-secondary !px-2.5 !py-1.5 text-xs" @click="setStatus(y, 'aktif')">
              <LockOpenIcon class="h-3.5 w-3.5" /> Buka Kembali
            </button>
            <button v-if="y.status === 'draft'" class="hc-btn-secondary !px-2.5 !py-1.5 text-xs !text-red-600 hover:!bg-red-50" @click="deleteYear(y)">
              <TrashIcon class="h-3.5 w-3.5" /> Hapus
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── UNIT KERJA ─────────────────────────────────────────────────────── -->
    <div v-else-if="tab === 'unit'">
      <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-600">Hierarki unit kerja: <b>Group → Department → Section</b>.</p>
        <button class="hc-btn" @click="openUnitModal(null)">
          <PlusIcon class="h-4 w-4" /> Tambah Unit
        </button>
      </div>
      <div class="hc-card divide-y divide-gray-50">
        <div
          v-for="u in unitTree" :key="u.id"
          class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50/60"
          :style="{ paddingLeft: (1 + u.depth * 1.75) + 'rem' }"
        >
          <span
            class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
            :class="{
              'bg-blue-50 text-blue-700': u.type === 'group',
              'bg-violet-50 text-violet-700': u.type === 'department',
              'bg-gray-100 text-gray-500': u.type === 'section',
            }"
          >{{ u.type }}</span>
          <span class="w-24 shrink-0 text-sm font-semibold text-gray-900">{{ u.code }}</span>
          <span class="min-w-0 flex-1 truncate text-sm text-gray-600">{{ u.name }}</span>
          <span v-if="!u.is_active" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-400">nonaktif</span>
          <button class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600" @click="openUnitModal(u)">
            <PencilSquareIcon class="h-4 w-4" />
          </button>
          <button class="rounded p-1 text-gray-300 hover:bg-red-50 hover:text-red-600" @click="deleteUnit(u)">
            <TrashIcon class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- ── JENIS BIAYA ────────────────────────────────────────────────────── -->
    <div v-else>
      <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-600">Struktur jenis biaya: <b>Kategori → Komponen</b>. Tag status menentukan asumsi kenaikan saat generate.</p>
        <button class="hc-btn" @click="openTypeModal(null)">
          <PlusIcon class="h-4 w-4" /> Tambah Jenis Biaya
        </button>
      </div>
      <div class="hc-card divide-y divide-gray-50">
        <div
          v-for="t in typeTree" :key="t.id"
          class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50/60"
          :style="{ paddingLeft: (1 + t.depth * 1.75) + 'rem' }"
        >
          <span class="w-36 shrink-0 truncate text-xs font-mono text-gray-400">{{ t.code }}</span>
          <span class="min-w-0 flex-1 truncate text-sm" :class="t.depth === 0 ? 'font-semibold text-gray-900' : 'text-gray-600'">{{ t.name }}</span>
          <span
            v-if="t.is_derived"
            class="inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
            :title="t.derived_note ?? 'Nominal mengacu ke jenis biaya lain'"
          ><LockClosedIcon class="h-3 w-3" /> referensi</span>
          <span
            v-for="s in statusList(t.employee_status)" :key="s"
            class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-500"
          >{{ s }}</span>
          <button class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600" @click="openTypeModal(t)">
            <PencilSquareIcon class="h-4 w-4" />
          </button>
          <button class="rounded p-1 text-gray-300 hover:bg-red-50 hover:text-red-600" @click="deleteType(t)">
            <TrashIcon class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- ── modal tahun baru / generate ────────────────────────────────────── -->
    <HcModal :show="yearModal" title="Tahun Anggaran Baru" @close="yearModal = false">
      <form class="space-y-4" @submit.prevent="submitYear">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Tahun</span>
          <input v-model.number="yearForm.year" type="number" min="2000" max="2100" required class="hc-input w-full" />
        </label>

        <fieldset class="rounded-lg border border-gray-200 p-3">
          <legend class="px-1 text-xs font-medium text-gray-600">Sumber data</legend>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input v-model="yearForm.mode" type="radio" value="generate" class="text-[#2a78d6]" />
            Generate dari tahun sebelumnya + asumsi kenaikan
          </label>
          <label class="mt-1.5 flex items-center gap-2 text-sm text-gray-700">
            <input v-model="yearForm.mode" type="radio" value="kosong" class="text-[#2a78d6]" />
            Buat kosong (input manual)
          </label>
        </fieldset>

        <template v-if="yearForm.mode === 'generate'">
          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-gray-600">Tahun sumber</span>
              <select v-model="yearForm.source_year_id" class="hc-select w-full">
                <option v-for="y in years" :key="y.id" :value="y.id">{{ y.year }}</option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-gray-600">Basis</span>
              <select v-model="yearForm.basis" class="hc-select w-full">
                <option value="rkap">RKAP</option>
                <option value="realisasi">Realisasi</option>
                <option value="prognosa">Prognosa</option>
              </select>
            </label>
          </div>
          <div>
            <p class="mb-1.5 text-xs font-medium text-gray-600">Asumsi kenaikan (%) per status pegawai</p>
            <div class="grid grid-cols-4 gap-2">
              <label v-for="s in ['tetap', 'kontrak', 'honor', 'direksi']" :key="s" class="block">
                <span class="mb-0.5 block text-[10px] uppercase text-gray-400">{{ s }}</span>
                <input v-model.number="yearForm.kenaikan[s]" type="number" step="any" class="hc-input w-full py-1 text-right text-sm" />
              </label>
            </div>
            <p class="mt-1.5 text-[11px] leading-relaxed text-gray-400">
              Komponen biaya mengikuti kenaikan sesuai tag status pegawainya; komponen lintas status memakai kenaikan pegawai tetap.
            </p>
          </div>
        </template>

        <div class="flex justify-end gap-2 pt-1">
          <button type="button" class="hc-btn-secondary" @click="yearModal = false">Batal</button>
          <button type="submit" class="hc-btn" :disabled="generating">
            {{ generating ? 'Memproses…' : (yearForm.mode === 'generate' ? 'Generate RKAP' : 'Buat Tahun') }}
          </button>
        </div>
      </form>
    </HcModal>

    <!-- ── modal unit ─────────────────────────────────────────────────────── -->
    <HcModal :show="unitModal" :title="editingUnit ? 'Ubah Unit Kerja' : 'Tambah Unit Kerja'" @close="unitModal = false">
      <form class="space-y-3" @submit.prevent="submitUnit">
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Kode</span>
            <input v-model="unitForm.code" type="text" required class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Level</span>
            <select v-model="unitForm.type" class="hc-select w-full">
              <option value="group">Group</option>
              <option value="department">Department</option>
              <option value="section">Section</option>
            </select>
          </label>
        </div>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Nama</span>
          <input v-model="unitForm.name" type="text" required class="hc-input w-full" />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Induk</span>
          <select v-model="unitForm.parent_id" class="hc-select w-full">
            <option :value="null">– (level teratas)</option>
            <option v-for="u in unitTree.filter(x => x.id !== editingUnit?.id)" :key="u.id" :value="u.id">
              {{ '—'.repeat(u.depth) }} {{ u.code }}
            </option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input v-model="unitForm.is_active" type="checkbox" class="rounded text-[#2a78d6]" /> Aktif
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="unitModal = false">Batal</button>
          <button type="submit" class="hc-btn">Simpan</button>
        </div>
      </form>
    </HcModal>

    <!-- ── modal jenis biaya ──────────────────────────────────────────────── -->
    <HcModal :show="typeModal" :title="editingType ? 'Ubah Jenis Biaya' : 'Tambah Jenis Biaya'" @close="typeModal = false">
      <form class="space-y-3" @submit.prevent="submitType">
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Kode</span>
            <input v-model="typeForm.code" type="text" required class="hc-input w-full" placeholder="mis. TUNJ.SERAGAM" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Kategori Induk</span>
            <select v-model="typeForm.parent_id" class="hc-select w-full">
              <option :value="null">– (kategori baru)</option>
              <option v-for="t in costTypes.filter(x => !x.parent_id && x.id !== editingType?.id)" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </label>
        </div>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Nama</span>
          <input v-model="typeForm.name" type="text" required class="hc-input w-full" />
        </label>
        <fieldset class="rounded-lg border border-gray-200 p-3">
          <legend class="px-1 text-xs font-medium text-gray-600">Status Pegawai Terkait (boleh lebih dari satu)</legend>
          <div class="grid grid-cols-2 gap-1.5">
            <label v-for="(label, s) in STATUS_OPTIONS" :key="s" class="flex items-center gap-2 text-sm text-gray-700">
              <input v-model="typeForm.employee_status" type="checkbox" :value="s" class="rounded text-[#2a78d6]" />
              {{ label }}
            </label>
          </div>
          <p class="mt-1.5 text-[11px] text-gray-400">Kosongkan semua bila komponen berlaku lintas status.</p>
        </fieldset>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input v-model="typeForm.is_derived" type="checkbox" class="rounded text-[#2a78d6]" />
          Nominal mengacu ke jenis biaya lain (tidak diinput manual)
        </label>
        <label v-if="typeForm.is_derived" class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Keterangan referensi</span>
          <input v-model="typeForm.derived_note" type="text" maxlength="200" class="hc-input w-full" placeholder="mis. % BPJS × Biaya Gaji" />
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="typeModal = false">Batal</button>
          <button type="submit" class="hc-btn">Simpan</button>
        </div>
      </form>
    </HcModal>
  </HcLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  CalendarDaysIcon, BuildingOffice2Icon, TagIcon, PlusIcon, TrashIcon,
  PencilSquareIcon, PlayIcon, LockClosedIcon, LockOpenIcon,
} from '@heroicons/vue/24/outline'

const STATUS_OPTIONS = { tetap: 'Tetap', kontrak: 'Kontrak', honor: 'Honor', direksi: 'Direksi' }
const statusList = (s) => s ? s.split(',').filter(Boolean) : []
import HcLayout from '@/Layouts/HcLayout.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  units: Array,
  costTypes: Array,
})

const { fmtShort } = useHcFormat()

const TABS = [
  { key: 'tahun', label: 'Tahun Anggaran', icon: CalendarDaysIcon },
  { key: 'unit', label: 'Unit Kerja', icon: BuildingOffice2Icon },
  { key: 'biaya', label: 'Jenis Biaya', icon: TagIcon },
]
const tab = ref('tahun')

// pohon rata (depth-first) untuk daftar unit & jenis biaya
function flatten(items) {
  const out = []
  const walk = (parentId, depth) => {
    items.filter(i => i.parent_id === parentId).forEach(i => {
      out.push({ ...i, depth })
      walk(i.id, depth + 1)
    })
  }
  walk(null, 0)
  return out
}
const unitTree = computed(() => flatten(props.units))
const typeTree = computed(() => flatten(props.costTypes))

const confirm = (title, text) => Swal.fire({
  title, text, icon: 'warning', showCancelButton: true,
  confirmButtonColor: '#d03b3b', confirmButtonText: 'Ya', cancelButtonText: 'Batal',
})

// ── tahun ────────────────────────────────────────────────────────────────
const yearModal = ref(false)
const generating = ref(false)
const yearForm = reactive({
  year: new Date().getFullYear() + 1,
  mode: 'generate',
  source_year_id: null,
  basis: 'rkap',
  kenaikan: { tetap: 5, kontrak: 4, honor: 7, direksi: 6 },
})

function openYearModal() {
  const latest = [...props.years].sort((a, b) => b.year - a.year)[0]
  yearForm.year = (latest?.year ?? new Date().getFullYear()) + 1
  yearForm.source_year_id = latest?.id ?? null
  yearModal.value = true
}

function submitYear() {
  generating.value = true
  router.post(route('hc.master.tahun.store'), {
    year: yearForm.year,
    source_year_id: yearForm.mode === 'generate' ? yearForm.source_year_id : null,
    basis: yearForm.basis,
    kenaikan: yearForm.mode === 'generate' ? yearForm.kenaikan : undefined,
  }, {
    preserveScroll: true,
    onSuccess: () => { yearModal.value = false },
    onFinish: () => { generating.value = false },
  })
}

function setStatus(y, status) {
  const labels = { aktif: 'diaktifkan', final: 'difinalkan (terkunci)' }
  confirm(`${y.year} akan ${labels[status] ?? status}`, status === 'aktif' ? 'Tahun aktif lain otomatis menjadi final.' : '')
    .then((res) => {
      if (res.isConfirmed) {
        router.put(route('hc.master.tahun.update', y.id), { status }, { preserveScroll: true })
      }
    })
}

function deleteYear(y) {
  confirm(`Hapus tahun ${y.year}?`, 'Seluruh data anggaran & asumsi tahun ini ikut terhapus.')
    .then((res) => {
      if (res.isConfirmed) router.delete(route('hc.master.tahun.destroy', y.id), { preserveScroll: true })
    })
}

// ── unit ─────────────────────────────────────────────────────────────────
const unitModal = ref(false)
const editingUnit = ref(null)
const unitForm = reactive({ code: '', name: '', type: 'department', parent_id: null, is_active: true })

function openUnitModal(u) {
  editingUnit.value = u
  Object.assign(unitForm, u
    ? { code: u.code, name: u.name, type: u.type, parent_id: u.parent_id, is_active: !!u.is_active }
    : { code: '', name: '', type: 'department', parent_id: null, is_active: true })
  unitModal.value = true
}

function submitUnit() {
  const opts = { preserveScroll: true, onSuccess: () => { unitModal.value = false } }
  if (editingUnit.value) {
    router.put(route('hc.master.unit.update', editingUnit.value.id), unitForm, opts)
  } else {
    router.post(route('hc.master.unit.store'), unitForm, opts)
  }
}

function deleteUnit(u) {
  confirm(`Hapus unit ${u.code}?`, u.name).then((res) => {
    if (res.isConfirmed) router.delete(route('hc.master.unit.destroy', u.id), { preserveScroll: true })
  })
}

// ── jenis biaya ──────────────────────────────────────────────────────────
const typeModal = ref(false)
const editingType = ref(null)
const typeForm = reactive({
  code: '', name: '', parent_id: null,
  employee_status: [], is_derived: false, derived_note: '',
})

function openTypeModal(t) {
  editingType.value = t
  Object.assign(typeForm, t
    ? {
        code: t.code, name: t.name, parent_id: t.parent_id,
        employee_status: statusList(t.employee_status),
        is_derived: !!t.is_derived, derived_note: t.derived_note ?? '',
      }
    : { code: '', name: '', parent_id: null, employee_status: [], is_derived: false, derived_note: '' })
  typeModal.value = true
}

function submitType() {
  const opts = { preserveScroll: true, onSuccess: () => { typeModal.value = false } }
  if (editingType.value) {
    router.put(route('hc.master.biaya.update', editingType.value.id), typeForm, opts)
  } else {
    router.post(route('hc.master.biaya.store'), typeForm, opts)
  }
}

function deleteType(t) {
  confirm(`Hapus jenis biaya ${t.code}?`, t.name).then((res) => {
    if (res.isConfirmed) router.delete(route('hc.master.biaya.destroy', t.id), { preserveScroll: true })
  })
}
</script>
