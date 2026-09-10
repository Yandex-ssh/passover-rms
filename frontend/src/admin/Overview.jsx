import { currency, dateTime, downloadJson, go, useData } from './data'
import { useState } from 'react'
import { MovementHistory } from './Operations'
import { Badge, Button, DataState, Empty, Metric, Modal, PageHeading, Panel, Table, Tabs } from './shared'

export function DashboardPage() {
  const resource = useData('/dashboard?limit=7', '/reports/transactions')
  const data = resource.data?.[0]?.data
  const transactions = resource.data?.[1]
  const inventory = data?.inventory || {}
  const sufficient = (inventory.tracked_items || 0) - (inventory.low_stock || 0) - (inventory.out_of_stock || 0)
  return <><PageHeading title="Dashboard" eyebrow="Overview" description="Welcome back, Admin!"><span className="a-date">{data?.period.date || new Date().toISOString().slice(0, 10)} · UTC</span></PageHeading><DataState resource={resource}>{data && <><div className="a-metrics a-metrics-five"><Metric icon="transactions" label="Total sales" value={currency(data.sales.total)} note="Paid sales today (UTC)" /><Metric icon="orders" label="Total orders" value={Object.values(data.orders).reduce((a, b) => a + b, 0)} note="View all orders" onClick={() => go('/admin/orders')} /><Metric icon="alert" label="Low stock items" value={inventory.low_stock} note="View items" onClick={() => go('/admin/inventory')} /><Metric icon="history" label="Transactions" value={transactions.meta?.total || 0} note="View all" onClick={() => go('/admin/transactions')} /><Metric icon="inventory" label="Inventory status" value={inventory.tracked_items ? `${Math.round(sufficient / inventory.tracked_items * 100)}%` : '—'} note="Sufficient stock · view details" onClick={() => go('/admin/inventory')} /></div><div className="a-dashboard-columns"><Panel title="Recent transactions" action={<button className="a-text-button" onClick={() => go('/admin/transactions')}>View all</button>}><Table headings={['ID', 'Table', 'Amount', 'Method', 'Date & time']} rows={transactions.data.slice(0, 5).map((row) => <tr key={row.id}><td><strong>{row.transaction_number}</strong></td><td>Table {row.table.table_number}</td><td>{currency(row.total_amount)}</td><td>{row.payment_methods.map((m) => m === 'gcash' ? 'GCash' : 'Cash').join(' / ') || '—'}</td><td>{dateTime(row.opened_at)}</td></tr>)} /><div className="a-panel-footer"><Button onClick={() => go('/admin/transactions')}>View all transactions</Button></div></Panel><Panel title="Low stock items" action={<button className="a-text-button" onClick={() => go('/admin/inventory')}>View all</button>}><Table headings={['Item', 'Current stock', 'Reorder level']} rows={data.low_stock_items.map((item) => <tr key={item.menu_item_id}><td>{item.name}</td><td>{item.quantity}</td><td>{item.low_stock_threshold}</td></tr>)} empty="All tracked items are above their low-stock threshold, or out of stock." /></Panel></div><Panel title="Inventory status" action={<button className="a-text-button" onClick={() => go('/admin/inventory')}>View details</button>}><div className="a-metrics"><Metric icon="inventory" label="Total items" value={inventory.tracked_items} note="Tracked menu items" /><Metric icon="check" label="Sufficient stock" value={sufficient} note="Above reorder level" /><Metric icon="alert" label="Low stock" value={inventory.low_stock} note="At or below reorder level" /><Metric icon="close" label="Out of stock" value={inventory.out_of_stock} note="No remaining units" /></div></Panel></>}</DataState></>
}

function periodRange(period) {
  const end = new Date()
  const start = new Date(end)
  if (period === 'weekly') start.setUTCDate(end.getUTCDate() - 6)
  if (period === 'monthly') start.setUTCDate(end.getUTCDate() - 29)
  return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) }
}

export function ReportsPage({ scope = 'admin' }) {
  const endpoint = (path) => scope === 'cashier' ? `/cashier${path}` : path
  const inventoryPath = scope === 'cashier' ? '/cashier/menu' : '/admin/inventory'
  const [period, setPeriod] = useState('weekly')
  const { from, to } = periodRange(period)
  const query = `from=${from}&to=${to}`
  const resource = useData(endpoint(`/reports/sales?${query}`), endpoint(`/reports/menu-items?${query}`), endpoint('/reports/inventory'))
  const sales = resource.data?.[0]?.data
  const items = resource.data?.[1]?.data || []
  const inventory = resource.data?.[2]?.data || []
  return <><PageHeading eyebrow="Business" title="Reports"><Tabs values={[["daily", 'Daily'], ["weekly", 'Weekly'], ["monthly", 'Monthly']]} value={period} onChange={setPeriod} /></PageHeading><div className="a-report-period"><span>{from} – {to} · UTC{period === 'monthly' ? ' · Last 30 days' : ''}</span><Button icon="download" disabled={!sales} onClick={() => downloadJson(`passover-report-${scope}-${from}-${to}.json`, { from, to, sales, menu_items: items, inventory })}>Export report</Button></div><DataState resource={resource}>{sales && <><div className="a-metrics"><Metric icon="reports" label="Total sales" value={currency(sales.total_sales)} note="Paid transactions in this period" /><Metric icon="transactions" label="Total transactions" value={sales.transaction_count} note="Paid transactions" /><Metric icon="coffee" label="Average transaction value" value={currency(sales.average_transaction_value)} note="Sales per paid transaction" /><Metric icon="inventory" label="Items sold" value={sales.item_quantity} note="Across all menu items" /></div><div className="a-report-charts"><Panel title={`Sales trend · ${period}`}><SalesChart dailySales={sales.daily_sales || []} from={from} to={to} /></Panel><Panel title="Payment breakdown"><PaymentChart methods={sales.payment_methods} /></Panel></div><div className="a-columns"><Panel title="Best-selling items">{items.length ? <div className="a-best-sellers">{items.slice(0, 5).map((item) => <div key={item.menu_item_id}><div><span>{item.name}</span><strong>{item.quantity_sold} sold</strong></div><div className="a-progress"><span style={{ width: `${item.quantity_sold / Math.max(1, items[0].quantity_sold) * 100}%` }} /></div></div>)}</div> : <Empty>No items sold in this period.</Empty>}</Panel><Panel title="Inventory report"><dl className="a-summary-list"><div><dt>Total tracked items</dt><dd>{inventory.length} items</dd></div><div><dt>Items currently low</dt><dd>{inventory.filter((i) => i.is_low_stock).length} items</dd></div><div><dt>Items out of stock</dt><dd>{inventory.filter((i) => i.is_out_of_stock).length} items</dd></div><div><dt>Available menu items</dt><dd>{inventory.filter((i) => i.is_available).length} items</dd></div></dl><p className="a-muted">Current inventory snapshot.</p><Button onClick={() => go(inventoryPath)}>View inventory history</Button></Panel></div></>}</DataState></>
}

function SalesChart({ dailySales, from, to }) {
  const days = []
  for (let date = new Date(`${from}T00:00:00Z`); date <= new Date(`${to}T00:00:00Z`); date.setUTCDate(date.getUTCDate() + 1)) {
    const key = date.toISOString().slice(0, 10)
    days.push({ date: key, total: Number(dailySales.find((d) => d.date === key)?.total || 0), label: date.toLocaleDateString('en', { weekday: 'short', timeZone: 'UTC' }) })
  }
  const max = Math.max(1, ...days.map((d) => d.total))
  return <div className="a-sales-chart" role="img" aria-label={`Daily sales from ${from} to ${to}. ${days.map((d) => `${d.date}: ${currency(d.total)}`).join('; ')}`}><div className="a-chart-scale"><span>{currency(max === 1 && days.every((d) => !d.total) ? 0 : max)}</span><span>{currency(max / 2)}</span><span>₱0</span></div><div className="a-chart-bars">{days.map((d) => <div className="a-chart-column" key={d.date}><div className="a-chart-bar-space"><div className="a-chart-bar" style={{ height: `${d.total / max * 100}%` }} title={`${d.date}: ${currency(d.total)}`} /></div><span>{days.length > 7 ? d.date.slice(-2) : d.label}</span></div>)}</div>{days.every((d) => !d.total) && <span className="a-chart-empty">No paid sales in this period</span>}</div>
}

function PaymentChart({ methods }) {
  const entries = Object.entries(methods || {})
  const total = entries.reduce((sum, [, value]) => sum + Number(value), 0)
  const colors = ['#333735', '#909690', '#cbd0ca']
  let angle = 0
  const stops = entries.map(([, value], i) => { const start = angle; angle += Number(value) / total * 360; return `${colors[i % colors.length]} ${start}deg ${angle}deg` })
  return <div className="a-payment-chart"><div className="a-donut" style={{ background: total ? `conic-gradient(${stops.join(',')})` : '#eef0ed' }} aria-hidden="true"><div><strong>{total ? '100%' : '—'}</strong><small>Payments</small></div></div><div className="a-chart-legend">{total ? entries.map(([method, amount], i) => <div key={method}><span style={{ background: colors[i % colors.length] }} /><div>{method === 'gcash' ? 'GCash' : 'Cash'}<small>{currency(amount)}</small></div><strong>{Math.round(Number(amount) / total * 100)}%</strong></div>) : <p className="a-muted">No completed payments.</p>}</div></div>
}

export function SystemPage() {
  const [historyOpen, setHistoryOpen] = useState(false)
  return <><PageHeading eyebrow="Configuration" title="System management" /><div className="a-columns a-system-columns"><Panel title="Operational settings"><p className="a-muted">Current ordering and inventory behavior.</p><div className="a-setting"><div><strong>Auto-confirm incoming orders</strong><p>Incoming QR orders require cashier confirmation.</p></div><Badge value="inactive">Off</Badge></div><div className="a-setting"><div><strong>Low-stock alerts</strong><p>Low-stock items appear on the dashboard and inventory page.</p></div><Badge value="active">On</Badge></div><div className="a-setting"><div><strong>Auto-release tables</strong><p>Automatic table release after payment is not configured.</p></div><Badge value="inactive">Unavailable</Badge></div><div className="a-setting"><div><strong>Collect guest feedback</strong><p>Guest ratings are not enabled for QR ordering.</p></div><Badge value="inactive">Unavailable</Badge></div><p className="a-setting-note">These settings are read-only. Configuration changes are not available yet.</p></Panel><div className="a-stack"><Panel title="Access & permissions"><p className="a-muted">Role capabilities and access control.</p><div className="a-role"><strong>Admin</strong><span>Users, tables, menu, inventory, reports, and order monitoring.</span></div><div className="a-role"><strong>Cashier</strong><span>Order confirmation, kitchen progress, billing, payments, and receipts.</span></div><Button onClick={() => go('/admin/users')}>Manage staff access</Button></Panel><Panel title="Data management"><Button icon="download" onClick={() => go('/admin/reports')}>Export business reports</Button><Button icon="history" onClick={() => setHistoryOpen(!historyOpen)}>{historyOpen ? 'Hide' : 'View'} stock change log</Button><div className="a-setting-note">Full system backups and demo-data resets are not available in this interface.</div>{historyOpen && <Modal title="Stock change log" onClose={() => setHistoryOpen(false)}><MovementHistory /></Modal>}</Panel></div></div></>
}
