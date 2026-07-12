// Format angka untuk Sistem Informasi RKAP HC (locale id-ID).
export const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
export const BULAN_PANJANG = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

export function useHcFormat() {
  const fmtIDR = (v) => {
    if (v === null || v === undefined || Number.isNaN(v)) return '–'
    return new Intl.NumberFormat('id-ID', {
      style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0,
    }).format(v)
  }

  // Angka ringkas: Rp 79,09 M (miliar), Rp 1,23 T, Rp 456,7 Jt
  const fmtShort = (v) => {
    if (v === null || v === undefined || Number.isNaN(v)) return '–'
    const abs = Math.abs(v)
    const fmt = (n, suffix) =>
      'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' ' + suffix
    if (abs >= 1e12) return fmt(v / 1e12, 'T')
    if (abs >= 1e9) return fmt(v / 1e9, 'M')
    if (abs >= 1e6) return fmt(v / 1e6, 'Jt')
    if (abs >= 1e3) return fmt(v / 1e3, 'Rb')
    return fmtIDR(v)
  }

  const fmtNum = (v) => (v === null || v === undefined) ? '–'
    : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(v)

  const fmtPct = (v, signed = false) => {
    if (v === null || v === undefined || Number.isNaN(v)) return '–'
    const s = v.toLocaleString('id-ID', { maximumFractionDigits: 1 })
    return (signed && v > 0 ? '+' : '') + s + '%'
  }

  return { fmtIDR, fmtShort, fmtNum, fmtPct }
}
