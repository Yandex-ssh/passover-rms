import { useEffect, useState } from 'react'
import { get } from '../api'

export const currency = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0))
export const dateTime = (value) => value ? new Date(value).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'
export const titleCase = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase())
export const queryString = (values) => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null)).toString()
export const go = (path) => { history.pushState({}, '', path); dispatchEvent(new PopStateEvent('popstate')) }

export function useData(...paths) {
  const key = JSON.stringify(paths)
  const [revision, setRevision] = useState(0)
  const [state, setState] = useState({ data: null, error: null, loading: true })
  useEffect(() => {
    let active = true
    setState({ data: null, error: null, loading: true })
    Promise.all(JSON.parse(key).map((path) => get(path))).then((data) => {
      if (active) setState({ data, error: null, loading: false })
    }).catch((error) => { if (active) setState({ data: null, error, loading: false }) })
    return () => { active = false }
  }, [key, revision])
  return { ...state, refresh: () => setRevision((v) => v + 1) }
}

export function downloadJson(name, data) { const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000) }

export function downloadExcel(name, report) {
  const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`
  const rows = [['Pass-over Cafe report'], ['Period from', report.from, 'Period to', report.to], [], ['Sales summary', 'Value'], ['Total sales', report.sales?.total_sales], ['Transactions', report.sales?.transaction_count], ['Average transaction value', report.sales?.average_transaction_value], ['Items sold', report.sales?.item_quantity], [], ['Daily sales', 'Total']]
  ;(report.sales?.daily_sales || []).forEach((row) => rows.push([row.date, row.total]))
  rows.push([], ['Best-selling items', 'Quantity sold'])
  ;(report.menu_items || []).forEach((item) => rows.push([item.name, item.quantity_sold]))
  rows.push([], ['Inventory', 'Low stock', 'Out of stock', 'Available'])
  ;(report.inventory || []).forEach((item) => rows.push([item.name, item.is_low_stock ? 'Yes' : 'No', item.is_out_of_stock ? 'Yes' : 'No', item.is_available ? 'Yes' : 'No']))
  const csv = '\ufeff' + rows.map((row) => row.map(escape).join(',')).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = name
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
