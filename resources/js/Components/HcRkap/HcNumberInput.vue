<template>
  <input
    ref="el"
    :value="text"
    type="text"
    inputmode="decimal"
    autocomplete="off"
    @input="onInput"
  />
</template>

<script setup>
// Input angka berformat Indonesia: pemisah ribuan "." dan desimal ",".
// v-model bernilai Number (atau null saat kosong); pemformatan berjalan
// langsung saat mengetik dengan posisi kursor dipertahankan.
import { nextTick, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  maxDecimals: { type: Number, default: 2 },
})
const emit = defineEmits(['update:modelValue'])

const el = ref(null)
defineExpose({
  focus: () => el.value?.focus(),
  select: () => el.value?.select(),
})

const format = (v) => {
  if (v === null || v === undefined || v === '') return ''
  const n = typeof v === 'number' ? v : parseFloat(String(v).replace(/\./g, '').replace(',', '.'))
  if (Number.isNaN(n)) return ''
  return n.toLocaleString('id-ID', { maximumFractionDigits: props.maxDecimals })
}

const parse = (t) => {
  if (!t) return null
  const n = parseFloat(t.replace(/\./g, '').replace(',', '.'))
  return Number.isNaN(n) ? null : n
}

const text = ref(format(props.modelValue))

watch(() => props.modelValue, (v) => {
  // jangan menimpa teks saat nilainya setara (mis. "1,50" vs 1.5 saat mengetik)
  if (parse(text.value) !== (v === '' || v === null || v === undefined ? null : Number(v))) {
    text.value = format(v)
  }
})

// paste gaya EN ("1,234,567.89") → normalisasi dulu ke gaya ID
function normalizeRaw(raw) {
  const lastDot = raw.lastIndexOf('.')
  const lastComma = raw.lastIndexOf(',')
  if (lastDot > lastComma && lastComma !== -1) {
    return raw.slice(0, lastDot).replace(/[,.]/g, '') + ',' + raw.slice(lastDot + 1)
  }
  if ((raw.match(/,/g) || []).length > 1 && lastDot === -1) {
    return raw.replace(/,/g, '') // banyak koma tanpa titik → koma ribuan gaya EN
  }
  return raw
}

// susun ulang: digit + satu koma desimal, ribuan diberi titik
function reformat(raw) {
  let s = normalizeRaw(raw).replace(/[^\d,]/g, '')
  const firstComma = s.indexOf(',')
  let int = firstComma === -1 ? s : s.slice(0, firstComma)
  const dec = firstComma === -1 ? null : s.slice(firstComma + 1).replace(/,/g, '').slice(0, props.maxDecimals)
  int = int.replace(/^0+(?=\d)/, '')
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return dec === null ? grouped : `${grouped},${dec}`
}

function onInput(event) {
  const input = event.target
  const sigBefore = input.value.slice(0, input.selectionStart).replace(/[^\d,]/g, '').length
  const formatted = reformat(input.value)

  text.value = formatted
  emit('update:modelValue', parse(formatted))

  nextTick(() => {
    if (input.value !== formatted) input.value = formatted
    let pos = 0
    let count = 0
    while (pos < formatted.length && count < sigBefore) {
      if (/[\d,]/.test(formatted[pos])) count++
      pos++
    }
    input.setSelectionRange(pos, pos)
  })
}

onMounted(() => {
  if ('autofocus' in el.value?.attributes) {
    el.value.focus()
  }
})
</script>
