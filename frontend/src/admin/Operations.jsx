import { currency, dateTime, go, queryString, useData } from './data'
import { useEffect, useState } from 'react'
import { post } from '../api'
import { Badge, Button, DataState, Empty, ErrorMessage, Field, Modal, PageHeading, Pagination, Panel, Table, Tabs } from './shared'

export function InventoryPage() {
  const resource = useData('/inventories')
  const [selected, setSelected] = useState(null)
  const [historyItem, setHistoryItem] = useState(null)
  const inventory = resource.data?.[0]?.data || []
  return <><PageHeading eyebrow="Stock control" title="Inventory" /><DataState resource={resource}><Panel className="a-table-panel"><Table headings={['Menu item', 'Current stock', 'Low stock at', 'Status', 'Actions']} rows={inventory.map((item) => <tr key={item.id}><td><strong>{item.menu_item.name}</strong></td><td>{item.quantity} units</td><td>{item.low_stock_threshold} units</td><td><Badge value={item.is_out_of_stock ? 'out-of-stock' : item.is_low_stock ? 'low-stock' : 'sufficient'}>{item.is_out_of_stock ? 'Out of stock' : item.is_low_stock ? 'Low stock' : 'Sufficient'}</Badge></td><td><div className="a-actions"><Button icon="history" onClick={() => setHistoryItem(item)}>History</Button><Button icon="plus" onClick={() => setSelected({ item, operation: 'restock' })}>Restock</Button><Button onClick={() => setSelected({ item, operation: 'adjust' })}>Adjust</Button></div></td></tr>)} /></Panel></DataState>{selected && <StockDialog {...selected} onClose={() => setSelected(null)} onSaved={resource.refresh} />}{historyItem && <Modal title={`Stock history · ${historyItem.menu_item.name}`} onClose={() => setHistoryItem(null)}><MovementHistory inventoryId={historyItem.id} /></Modal>}</>
}

function StockDialog({ item, operation, onClose, onSaved }) {
  const [quantity, setQuantity] = useState(operation === 'restock' ? 1 : item.quantity)
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  return <Modal title={`${operation === 'restock' ? 'Restock' : 'Adjust stock'} · ${item.menu_item.name}`} onClose={() => !busy && onClose()}><form className="a-form" onSubmit={async (event) => { event.preventDefault(); setBusy(true); setError(null); try { await post(`/inventories/${item.id}/${operation}`, { quantity: Number(quantity), reason: reason || (operation === 'restock' ? 'Stock replenishment' : 'Physical count correction') }); onSaved(); onClose() } catch (e) { setError(e) } finally { setBusy(false) } }}><ErrorMessage error={error} /><p className="a-muted">Current stock: {item.quantity} units</p><Field label={operation === 'restock' ? 'Quantity to add' : 'Verified stock quantity'} type="number" min={operation === 'restock' ? 1 : 0} step={1} required value={quantity} onChange={(e) => setQuantity(e.target.value)} /><Field label="Reason" maxLength={255} required={operation === 'adjust'} value={reason} onChange={(e) => setReason(e.target.value)} /><div className="a-form-footer"><Button type="button" disabled={busy} onClick={onClose}>Cancel</Button><Button primary disabled={busy}>{busy ? 'Saving…' : operation === 'restock' ? 'Add stock' : 'Save adjustment'}</Button></div></form></Modal>
}

export function MovementHistory({ inventoryId }) {
  const [page, setPage] = useState(1)
  const resource = useData(`/reports/inventory-movements?${queryString({ inventory_id: inventoryId, page })}`)
  const result = resource.data?.[0]
  return <DataState resource={resource}><Table headings={['Item', 'Movement', 'Change', 'Before → after', 'Reason', 'Date']} rows={(result?.data || []).map((row) => <tr key={row.id}><td>{row.menu_item_name}</td><td><Badge value={row.type} /></td><td>{row.quantity_change > 0 ? '+' : ''}{row.quantity_change}</td><td>{row.quantity_before} → {row.quantity_after}</td><td>{row.reason || '—'}</td><td>{dateTime(row.created_at)}</td></tr>)} empty="No stock movements recorded." /><Pagination meta={result?.meta} onPage={setPage} /></DataState>
}

export function OrdersPage() {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [tick, setTick] = useState(0)
  useEffect(() => { const timer = setInterval(() => setTick((value) => value + 1), 30000); return () => clearInterval(timer) }, [])
  const resource = useData(`/admin/orders?${queryString({ status, page, refresh: tick })}`)
  const result = resource.data?.[0]
  return <><PageHeading eyebrow="Live service" title="Orders" description="Monitor incoming orders and kitchen progress. Cashiers manage order status."><Button icon="history" onClick={resource.refresh}>Refresh</Button></PageHeading><Tabs values={['', 'pending', 'confirmed', 'preparing', 'completed', 'rejected', 'cancelled'].map((s) => [s, s ? s[0].toUpperCase() + s.slice(1) : 'All'])} value={status} onChange={(s) => { setStatus(s); setPage(1) }} /><DataState resource={resource}><div className="a-card-grid a-orders-grid">{(result?.data || []).map((order) => <article className="a-order-card" key={order.id}><div className="a-card-heading"><h3>{order.order_number}</h3><Badge value={order.status} /></div><p className="a-order-meta">Table {order.table_session?.table?.table_number || '—'} · {dateTime(order.submitted_at)}</p><div className="a-order-items">{order.items.map((item) => <div key={item.id}><span>{item.quantity} ×</span><div>{item.menu_item?.name || 'Menu item'}{item.special_instruction && <small>{item.special_instruction}</small>}</div></div>)}</div>{order.customer_note && <p className="a-muted">{order.customer_note}</p>}<div className="a-card-heading"><strong>{currency(order.subtotal)}</strong><Badge value={order.dining_transaction?.status || 'pending'}>{order.dining_transaction ? `${order.dining_transaction.status === 'paid' ? 'Closed' : 'Open'} bill` : 'Awaiting confirmation'}</Badge></div>{order.dining_transaction && <Button icon="chevron" onClick={() => go(`/admin/transactions?search=${encodeURIComponent(order.dining_transaction.transaction_number)}`)}>View transaction</Button>}</article>)}</div>{!result?.data.length && <Empty>No {status || 'matching'} orders.</Empty>}<Pagination meta={result?.meta} onPage={setPage} /></DataState></>
}

export function TransactionsPage() {
  const initialSearch = new URLSearchParams(location.search).get('search') || ''
  const [draft, setDraft] = useState({ search: initialSearch, status: '', from: '', to: '' })
  const [filters, setFilters] = useState(draft)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState(null)
  const resource = useData(`/reports/transactions?${queryString({ ...filters, page })}`)
  const result = resource.data?.[0]
  const change = (key, value) => setDraft({ ...draft, [key]: value })
  return <><PageHeading eyebrow="Payment records" title="Transactions" /><form className="a-filters" onSubmit={(e) => { e.preventDefault(); setPage(1); setFilters({ ...draft }) }}><Field label="Search transactions" placeholder="Search by order, table, or transaction ID" maxLength={100} value={draft.search} onChange={(e) => change('search', e.target.value)} /><Field label="Status"><select value={draft.status} onChange={(e) => change('status', e.target.value)}><option value="">All statuses</option><option value="open">Open</option><option value="partially_paid">Partially paid</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select></Field><Field label="From (UTC)" type="date" value={draft.from} max={draft.to || undefined} onChange={(e) => change('from', e.target.value)} /><Field label="To (UTC)" type="date" value={draft.to} min={draft.from || undefined} onChange={(e) => change('to', e.target.value)} /><Button icon="search">Apply</Button></form><DataState resource={resource}><Panel className="a-table-panel"><Table headings={['Transaction', 'Order', 'Table', 'Amount', 'Method', 'Status', 'Date']} rows={(result?.data || []).map((row) => <tr key={row.id}><td><button className="a-text-button" onClick={() => setSelected(row)}>{row.transaction_number}</button></td><td>{row.order_numbers?.join(', ') || '—'}</td><td>Table {row.table?.table_number || '—'}</td><td><strong>{currency(row.total_amount)}</strong></td><td>{row.payment_methods?.map((method) => method === 'gcash' ? 'GCash' : 'Cash').join(' / ') || '—'}</td><td><Badge value={row.status} /></td><td>{dateTime(row.opened_at)}</td></tr>)} /><Pagination meta={result?.meta} onPage={setPage} /></Panel></DataState>{selected && <Modal title={selected.transaction_number} onClose={() => setSelected(null)}><dl className="a-summary-list"><div><dt>Status</dt><dd><Badge value={selected.status} /></dd></div><div><dt>Total</dt><dd>{currency(selected.total_amount)}</dd></div><div><dt>Paid</dt><dd>{currency(selected.paid_amount)}</dd></div><div><dt>Remaining balance</dt><dd>{currency(selected.remaining_balance)}</dd></div><div><dt>Receipt</dt><dd>{selected.receipt?.receipt_number || 'Not issued'}</dd></div></dl></Modal>}</>
}
