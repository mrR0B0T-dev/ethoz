<template>
  <HcLayout title="Asumsi RKAP">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <p class="max-w-2xl text-sm text-gray-600">
        Asumsi kenaikan &amp; tarif tahun <b>{{ tahun.year }}</b>. Nilai ini menjadi dasar perhitungan
        modul <b>Pegawai &amp; Biaya</b> serta <b>generate RKAP tahun berikutnya</b> di Master Data.
      </p>
      <button v-if="canEdit" class="hc-btn" @click="openCreate">
        <PlusIcon class="h-4 w-4" /> Tambah Asumsi
      </button>
    </div>

    <div v-if="!canEdit" class="mb-5 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      <LockClosedIcon class="h-4 w-4 shrink-0" />
      Tahun {{ tahun.year }} berstatus final — asumsi terkunci dan hanya bisa dibaca.
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div v-for="(items, category) in assumptions" :key="category" class="hc-card">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3">
          <component :is="categoryMeta(category).icon" class="h-4 w-4 text-[#2a78d6]" />
          <h2 class="text-sm font-semibold text-gray-900">{{ categoryMeta(category).label }}</h2>
        </div>
        <ul class="divide-y divide-gray-50">
          <li v-for="a in items" :key="a.id" class="flex items-center gap-3 px-4 py-2.5">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm text-gray-800">{{ a.label }}</p>
              <p class="text-[11px] text-gray-400">
                <span
                  v-for="s in statusList(a.applies_to)" :key="s"
                  class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 font-medium uppercase text-gray-500"
                >{{ s }}</span>
                {{ a.code }}
              </p>
            </div>

            <template v-if="editingId === a.id">
              <HcNumberInput
                v-model="editValue"
                class="hc-input w-24 py-1 text-right text-sm"
                @keyup.enter="saveEdit(a)"
                @keyup.esc="editingId = null"
              />
              <button class="rounded p-1 text-emerald-600 hover:bg-emerald-50" @click="saveEdit(a)">
                <CheckIcon class="h-4 w-4" />
              </button>
            </template>
            <template v-else>
              <span class="text-sm font-semibold tabular-nums text-gray-900">
                {{ displayValue(a) }}
              </span>
              <button
                v-if="canEdit"
                class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600"
                title="Ubah nilai"
                @click="startEdit(a)"
              >
                <PencilSquareIcon class="h-4 w-4" />
              </button>
              <button
                v-if="canEdit"
                class="rounded p-1 text-gray-300 hover:bg-red-50 hover:text-red-600"
                title="Hapus"
                @click="confirmDelete(a)"
              >
                <TrashIcon class="h-4 w-4" />
              </button>
            </template>
          </li>
        </ul>
      </div>
    </div>

    <!-- modal tambah -->
    <HcModal :show="modal" title="Tambah Asumsi" @close="modal = false">
      <form class="space-y-3" @submit.prevent="submitCreate">
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Kode (unik)</span>
            <input v-model="form.code" type="text" required class="hc-input w-full" placeholder="mis. kenaikan_ump" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Kategori</span>
            <select v-model="form.category" class="hc-select w-full">
              <option v-for="(meta, c) in CATEGORY_META" :key="c" :value="c">{{ meta.label }}</option>
            </select>
          </label>
        </div>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Label</span>
          <input v-model="form.label" type="text" required class="hc-input w-full" />
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tipe Nilai</span>
            <select v-model="form.value_type" class="hc-select w-full">
              <option value="persen">Persen (%)</option>
              <option value="nominal">Nominal (Rp)</option>
              <option value="bulan">Bulan (×gaji)</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Nilai</span>
            <HcNumberInput v-model="form.value" required class="hc-input w-full" />
          </label>
        </div>
        <fieldset class="rounded-lg border border-gray-200 p-3">
          <legend class="px-1 text-xs font-medium text-gray-600">Status Pegawai (boleh lebih dari satu)</legend>
          <div class="grid grid-cols-2 gap-1.5">
            <label v-for="(label, s) in STATUS_OPTIONS" :key="s" class="flex items-center gap-2 text-sm text-gray-700">
              <input v-model="form.applies_to" type="checkbox" :value="s" class="rounded text-[#2a78d6]" />
              {{ label }}
            </label>
          </div>
          <p class="mt-1.5 text-[11px] text-gray-400">Kosongkan semua bila asumsi berlaku untuk seluruh status.</p>
        </fieldset>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="modal = false">Batal</button>
          <button type="submit" class="hc-btn">Tambah</button>
        </div>
      </form>
    </HcModal>
  </HcLayout>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  ArrowTrendingUpIcon, BanknotesIcon, CheckIcon, GiftIcon, LockClosedIcon,
  PencilSquareIcon, PlusIcon, ReceiptPercentIcon, ShieldCheckIcon, TrashIcon, TagIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import HcNumberInput from '@/Components/HcRkap/HcNumberInput.vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  assumptions: Object, // dikelompokkan per kategori
  canEdit: Boolean,
})

const { fmtIDR } = useHcFormat()

const CATEGORY_META = {
  kenaikan_gaji: { label: 'Kenaikan Gaji', icon: ArrowTrendingUpIcon },
  tunjangan: { label: 'Tunjangan (bulan × gaji)', icon: GiftIcon },
  pajak: { label: 'Pajak', icon: ReceiptPercentIcon },
  fee: { label: 'Fee Pihak Ke-3', icon: BanknotesIcon },
  iuran: { label: 'Iuran & BPJS', icon: ShieldCheckIcon },
  lainnya: { label: 'Lainnya', icon: TagIcon },
}
const categoryMeta = (c) => CATEGORY_META[c] ?? CATEGORY_META.lainnya

const STATUS_OPTIONS = { tetap: 'Tetap', kontrak: 'Kontrak', honor: 'Honor', direksi: 'Direksi' }
const statusList = (s) => s ? s.split(',').filter(Boolean) : []

const displayValue = (a) => {
  if (a.value_type === 'persen') return a.value.toLocaleString('id-ID') + '%'
  if (a.value_type === 'bulan') return a.value.toLocaleString('id-ID') + ' bln'
  return fmtIDR(a.value)
}

// edit inline
const editingId = ref(null)
const editValue = ref(0)

function startEdit(a) {
  editingId.value = a.id
  editValue.value = a.value
}

function saveEdit(a) {
  if (editingId.value !== a.id) return
  editingId.value = null
  router.put(route('hc.asumsi.update', a.id), {
    value: editValue.value,
    tahun: props.tahun.year,
  }, { preserveScroll: true })
}

function confirmDelete(a) {
  Swal.fire({
    title: 'Hapus asumsi?', text: a.label, icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus', cancelButtonText: 'Batal',
  }).then((res) => {
    if (res.isConfirmed) router.delete(route('hc.asumsi.destroy', a.id), { preserveScroll: true })
  })
}

// tambah
const modal = ref(false)
const form = reactive({
  code: '', label: '', category: 'kenaikan_gaji',
  value_type: 'persen', value: 0, applies_to: [],
})

function openCreate() {
  Object.assign(form, { code: '', label: '', category: 'kenaikan_gaji', value_type: 'persen', value: 0, applies_to: [] })
  modal.value = true
}

function submitCreate() {
  router.post(route('hc.asumsi.store'), {
    ...form,
    fiscal_year_id: props.tahun.id,
    tahun: props.tahun.year,
  }, {
    preserveScroll: true,
    onSuccess: () => { modal.value = false },
  })
}
</script>
