import { useEffect, useState } from 'react'
import { get, post, put } from '../api'
import { Badge, Button, DataState, Empty, ErrorMessage, Field, Icon, Metric, Modal, PageHeading, Pagination, Panel, Table, Tabs } from '../admin/shared'
import { currency, dateTime, go, queryString, useData } from '../admin/data'
import { ReportsPage as SharedReportsPage } from '../admin/Overview'
import { usePollingData } from '../api/usePollingData'
import { CloseSession, KitchenSlipPreview, OrderEntry } from './ServiceDialogs'

const tableNumber = (record) => record?.table_session?.table?.table_number
  || record?.table_session?.restaurant_table?.table_number
  || record?.table?.table_number || '—'

export function CashierDashboard() {
  const resource = useData('/cashier/dashboard?limit=7', '/cashier/reports/transactions')
  const data = resource.data?.[0]?.data
  const transactions = resource.data?.[1]
  const inventory = data?.inventory || {}
  const orders = data?.orders || {}
  const totalOrders = Object.values(orders).reduce((total, value) => total + Number(value || 0), 0)
  const sufficient = (inventory.tracked_items || 0) - (inventory.low_stock || 0) - (inventory.out_of_stock || 0)
  return <><PageHeading title="Dashboard" eyebrow="Overview" description="Current service and payment activity."><span className="a-date">{data?.period?.date || new Date().toISOString().slice(0, 10)} · UTC</span></PageHeading><DataState resource={resource}>{data && <><div className="a-metrics a-metrics-five"><Metric icon="transactions" label="Total sales" value={currency(data.sales.total)} note="Paid sales today (UTC)" /><Metric icon="orders" label="Total orders" value={totalOrders} note="View all orders" onClick={() => go('/cashier/orders')} /><Metric icon="alert" label="Low stock items" value={inventory.low_stock || 0} note="View items" onClick={() => go('/cashier/menu')} /><Metric icon="history" label="Transactions" value={transactions?.meta?.total || 0} note="View all" onClick={() => go('/cashier/transactions')} /><Metric icon="inventory" label="Inventory status" value={inventory.tracked_items ? `${Math.round(sufficient / inventory.tracked_items * 100)}%` : '—'} note="Sufficient stock · view details" onClick={() => go('/cashier/menu')} /></div><div className="a-dashboard-columns"><Panel title="Recent transactions" action={<button className="a-text-button" onClick={() => go('/cashier/transactions')}>View all</button>}><Table headings={['ID', 'Table', 'Amount', 'Method', 'Date & time']} rows={(transactions?.data || []).slice(0, 5).map((row) => <tr key={row.id}><td><strong>{row.transaction_number}</strong></td><td>Table {row.table?.table_number || '—'}</td><td>{currency(row.total_amount)}</td><td>{row.payment_methods?.map((method) => method === 'gcash' ? 'GCash' : 'Cash').join(' / ') || '—'}</td><td>{dateTime(row.opened_at)}</td></tr>)} /><div className="a-panel-footer"><Button onClick={() => go('/cashier/transactions')}>View all transactions</Button></div></Panel><Panel title="Low stock items" action={<button className="a-text-button" onClick={() => go('/cashier/menu')}>View all</button>}><Table headings={['Item', 'Current stock', 'Reorder level']} rows={(data.low_stock_items || []).map((item) => <tr key={item.menu_item_id}><td>{item.name}</td><td>{item.quantity}</td><td>{item.low_stock_threshold}</td></tr>)} empty="All tracked items are above their low-stock threshold, or out of stock." /></Panel></div><Panel title="Active order status" action={<button className="a-text-button" onClick={() => go('/cashier/orders')}>View details</button>}><div className="a-metrics"><Metric icon="orders" label="Pending" value={orders.pending || 0} note="Awaiting confirmation" onClick={() => go('/cashier/orders?status=pending')} /><Metric icon="check" label="Confirmed" value={orders.confirmed || 0} note="Ready for kitchen" onClick={() => go('/cashier/orders?status=confirmed')} /><Metric icon="history" label="Preparing" value={orders.preparing || 0} note="In the kitchen" onClick={() => go('/cashier/orders?status=preparing')} /><Metric icon="check" label="Completed" value={orders.completed || 0} note="Ready for billing" onClick={() => go('/cashier/orders?status=completed')} /></div></Panel></>}</DataState></>
}

export function CashierTables() {
  const resource = usePollingData('/cashier/tables')
  const [qr, setQr] = useState(null)
  const [closing, setClosing] = useState(null)
  const [qrError, setQrError] = useState(false)
  const tables = [...(resource.data?.[0]?.data || [])].sort((a, b) => a.table_number.localeCompare(b.table_number, undefined, { numeric: true }))
  return <>
    <PageHeading eyebrow="Dining floor" title="Tables & QR" description="View table availability and close settled dining sessions." />
    <DataState resource={resource}><div className="a-card-grid a-tables">{tables.map((table) =>
      <article className="a-table-card" key={table.id}>
        <div className="a-card-heading"><div><span className="a-eyebrow">Table</span><h2>{table.table_number.padStart(2, '0')}</h2></div><Badge value={table.status} /></div>
        <p>Seats {table.capacity}</p>
        <div className="a-actions"><Button icon="tables" onClick={() => { setQr(table); setQrError(false) }}>View QR</Button>
        {table.active_session && <Button onClick={() => setClosing(table)}>Close session</Button>}</div>
      </article>)}</div>{!tables.length && <Empty>No tables available.</Empty>}
    </DataState>
    {qr && <Modal title={`Table ${qr.table_number} · Ordering QR`} onClose={() => setQr(null)}><div className="a-qr">
      {qrError ? <ErrorMessage error={{ message: 'QR code could not be loaded.' }} /> : <img src={`${(import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')}/cashier/tables/${qr.id}/qr`} alt={`Ordering QR for table ${qr.table_number}`} onError={() => setQrError(true)} />}
      <p>Guests can scan this code to order from this table.</p>
    </div></Modal>}
    {closing && <CloseSession table={closing} onClose={() => setClosing(null)} onClosed={() => { setClosing(null); resource.refresh() }} />}
  </>
}

export function CashierCategories() {
  const resource = useData('/categories', '/menu-items')
  const categories = resource.data?.[0]?.data || []
  const items = resource.data?.[1]?.data || []
  return <><PageHeading eyebrow="Menu structure" title="Categories" description="Current customer menu categories." /><DataState resource={resource}><Panel className="a-table-panel"><Table headings={['Category', 'Items', 'Status']} rows={categories.map((category) => <tr key={category.id}><td><strong>{category.name}</strong><small>{category.description}</small></td><td>{items.filter((item) => item.category_id === category.id).length} items</td><td><Badge value={category.is_active ? 'active' : 'inactive'} /></td></tr>)} /></Panel></DataState></>
}

export function CashierMenu() {
  const resource = useData('/categories', '/cashier/menu-items')
  const [category, setCategory] = useState('all')
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const categories = resource.data?.[0]?.data || []
  const items = resource.data?.[1]?.data || []
  const visible = items.filter((item) => category === 'all' || String(item.category_id) === category)
  const refreshMenu = resource.refresh
  useEffect(() => { const timer = setInterval(() => refreshMenu(), 5000); return () => clearInterval(timer) }, [refreshMenu])
  const toggleAvailability = async (item) => { setBusyId(item.id); setError(null); try { await put(`/cashier/menu-items/${item.id}/availability`, { is_available: !item.is_available }); resource.refresh() } catch (requestError) { setError(requestError) } finally { setBusyId(null) } }
  return <><PageHeading eyebrow="Kitchen catalog" title="Menu" description="Current prices, remaining stock, and customer availability." /><ErrorMessage error={error} /><DataState resource={resource}><Tabs values={[["all", 'All'], ...categories.filter((c) => c.is_active).map((c) => [String(c.id), c.name])]} value={category} onChange={setCategory} /><div className="a-card-grid a-menu-grid">{visible.map((item) => { const quantity = item.inventory?.quantity; const outOfStock = quantity === 0; const lowStock = quantity > 0 && quantity <= item.inventory?.low_stock_threshold; return <article className="a-menu-card" key={item.id}><div className="a-item-icon"><Icon name="menu" size={30} /></div><div className="a-card-heading"><h3>{item.name}</h3><strong>{currency(item.price)}</strong></div><small>{item.category?.name}</small><p>{item.description || 'No description added.'}</p><div className="a-badges"><Badge value={item.is_available ? 'available' : 'unavailable'} />{typeof quantity === 'number' && <Badge value={outOfStock ? 'out-of-stock' : lowStock ? 'low-stock' : 'in-stock'}>{outOfStock ? 'Out of stock' : lowStock ? `Low: ${quantity} left` : `${quantity} left`}</Badge>}</div><div className="a-actions"><Button icon="power" disabled={busyId === item.id || (!item.is_available && outOfStock)} onClick={() => toggleAvailability(item)}>{busyId === item.id ? 'Saving…' : item.is_available ? 'Mark unavailable' : outOfStock ? 'Restock required' : 'Mark available'}</Button></div></article> })}</div>{!visible.length && <Empty>No menu items in this category.</Empty>}</DataState></>
}

export function CashierOrders() {
  const initialStatus = new URLSearchParams(location.search).get('status') || 'all'
  const statuses = ['all', 'pending', 'confirmed', 'preparing', 'completed', 'rejected', 'cancelled']
  const [status, setStatus] = useState(statuses.includes(initialStatus) ? initialStatus : 'all')
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [creating, setCreating] = useState(false)
  const [ticketId, setTicketId] = useState(null)
  const resource = usePollingData('/cashier/orders?status=all')
  const orders = resource.data?.[0]?.data || []
  const visible = orders.filter((order) => status === 'all' || order.status === status)
  const process = async (order, action) => {
    setBusyId(order.id); setError(null)
    try {
      const path = order.status === 'pending' ? `/cashier/orders/${order.id}/${action}` : `/kitchen-tickets/${order.id}/${action}`
      await post(path, {}); resource.refresh()
    } catch (requestError) { setError(requestError) } finally { setBusyId(null) }
  }
  return <>
    <PageHeading eyebrow="Live service" title="Orders" description="Confirm incoming orders and update kitchen progress."><div className="a-actions"><Button primary icon="plus" onClick={() => setCreating(true)}>New order</Button><Button icon="history" onClick={resource.refresh}>Refresh</Button></div></PageHeading>
    <ErrorMessage error={error} />
    <Tabs values={statuses.map((value) => [value, value[0].toUpperCase() + value.slice(1)])} value={status} onChange={setStatus} />
    <DataState resource={resource}><div className="a-card-grid a-orders-grid">{visible.map((order) =>
      <article className="a-order-card" key={order.id}>
        <div className="a-card-heading"><h3>{order.order_number}</h3><Badge value={order.status} /></div>
        <p className="a-order-meta">Table {tableNumber(order)} · {dateTime(order.submitted_at || order.confirmed_at)}</p>
        <div className="a-order-items">{(order.items || []).map((item) => <div key={item.id}><span>{item.quantity} ×</span><div>{item.menu_item?.name || item.menuItem?.name || item.name}{item.special_instruction && <small>{item.special_instruction}</small>}</div></div>)}</div>
        {order.customer_note && <p className="a-muted">{order.customer_note}</p>}
        <strong>{currency(order.subtotal)}</strong>
        <div className="a-actions">
          {order.status === 'pending' && <><Button primary disabled={busyId === order.id} onClick={() => process(order, 'confirm')}>Confirm order</Button><Button disabled={busyId === order.id} onClick={() => process(order, 'reject')}>Reject</Button></>}
          {order.status === 'confirmed' && <Button primary disabled={busyId === order.id} onClick={() => process(order, 'prepare')}>Mark preparing</Button>}
          {order.status === 'preparing' && <Button primary disabled={busyId === order.id} onClick={() => process(order, 'complete')}>Complete order</Button>}
          {order.kitchen_ticket && <Button onClick={() => setTicketId(order.kitchen_ticket.id)}>Kitchen slip</Button>}
        </div>
      </article>)}</div>{!visible.length && <Empty>No {status === 'all' ? 'orders' : status} orders.</Empty>}
    </DataState>
    {creating && <OrderEntry onClose={() => setCreating(false)} onCreated={() => { setCreating(false); setStatus('pending'); resource.refresh() }} />}
    {ticketId && <KitchenSlipPreview ticketId={ticketId} onClose={() => setTicketId(null)} />}
  </>
}

export function LegacyCashierTransactions() {
  const [draft, setDraft] = useState({ search: '', status: '' })
  const [filters, setFilters] = useState(draft)
  const [page, setPage] = useState(1)
  const [bill, setBill] = useState(null)
  const [receiptId, setReceiptId] = useState(null)
  const [error, setError] = useState(null)
  const resource = useData(`/cashier/reports/transactions?${queryString({ ...filters, page })}`)
  const result = resource.data?.[0]
  const openBill = async (row) => { setError(null); try { setBill((await get(`/cashier/transactions/${row.id}/bill`)).data) } catch (e) { setError(e) } }
  return <><PageHeading eyebrow="Payment records" title="Transactions" description="Open bills, collect payments, and print completed receipts." /><ErrorMessage error={error} /><form className="a-filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters({ ...draft }) }}><Field label="Search transactions" placeholder="Order, table, or transaction ID" value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} /><Field label="Status"><select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">All statuses</option><option value="open">Open</option><option value="partially_paid">Partially paid</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select></Field><Button icon="search">Apply</Button></form><DataState resource={resource}><Panel className="a-table-panel"><Table headings={['Transaction', 'Order', 'Table', 'Total', 'Paid', 'Status', 'Action']} rows={(result?.data || []).map((row) => <tr key={row.id}><td><strong>{row.transaction_number}</strong></td><td>{row.order_numbers?.join(', ') || '—'}</td><td>Table {row.table?.table_number || '—'}</td><td>{currency(row.total_amount)}</td><td>{currency(row.paid_amount)}</td><td><Badge value={row.status} /></td><td><div className="a-actions"><Button onClick={() => openBill(row)}>{row.status === 'paid' ? 'View bill' : 'Open bill'}</Button>{row.receipt && <Button onClick={() => setReceiptId(row.receipt.id)}>Receipt</Button>}</div></td></tr>)} /><Pagination meta={result?.meta} onPage={setPage} /></Panel></DataState>{bill && <PaymentDialog initialBill={bill} onClose={() => setBill(null)} onPaid={(receipt) => { setBill(null); resource.refresh(); if (receipt?.id) setReceiptId(receipt.id) }} />}{receiptId && <ReceiptPreview receiptId={receiptId} onClose={() => { setReceiptId(null); resource.refresh() }} />}</>
}

function PaymentDialog({ initialBill, onClose, onPaid }) {
  const [bill, setBill] = useState(initialBill)
  const [method, setMethod] = useState('cash')
  const [amount, setAmount] = useState(initialBill.remaining_balance)
  const [reference, setReference] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const paid = bill.status === 'paid' || Number(bill.remaining_balance) <= 0
  const submit = async (event) => { event.preventDefault(); setBusy(true); setError(null); try { const payload = method === 'cash' ? { amount_received: amount, idempotency_key: crypto.randomUUID() } : { amount, reference_number: reference, idempotency_key: crypto.randomUUID() }; const result = (await post(`/cashier/transactions/${bill.id}/payments/${method}`, payload)).data; const next = { ...bill, ...(result.transaction || result) }; setBill(next); if (next.status === 'paid' || Number(next.remaining_balance) <= 0) onPaid(result.receipt) } catch (e) { setError(e) } finally { setBusy(false) } }
  return <Modal title={`Bill · ${bill.transaction_number}`} onClose={() => !busy && onClose()}><ErrorMessage error={error} /><dl className="a-summary-list"><div><dt>Table</dt><dd>{bill.table?.table_number || '—'}</dd></div><div><dt>Total</dt><dd>{currency(bill.total_amount)}</dd></div><div><dt>Paid</dt><dd>{currency(bill.paid_amount)}</dd></div><div><dt>Remaining</dt><dd>{currency(bill.remaining_balance)}</dd></div></dl>{paid ? <Badge value="paid">Paid</Badge> : <form className="a-form" onSubmit={submit}><Field label="Payment method"><select value={method} onChange={(e) => setMethod(e.target.value)}><option value="cash">Cash</option><option value="gcash">GCash</option></select></Field><Field label={method === 'cash' ? 'Amount received' : 'Amount to apply'} type="number" min={method === 'cash' ? bill.remaining_balance : 0.01} max={method === 'gcash' ? bill.remaining_balance : undefined} step="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} />{method === 'gcash' && <Field label="GCash reference number" required value={reference} onChange={(e) => setReference(e.target.value)} />}<div className="a-form-footer"><Button type="button" onClick={onClose}>Cancel</Button><Button primary disabled={busy}>{busy ? 'Recording…' : 'Record payment'}</Button></div></form>}</Modal>
}

function ReceiptPreview({ receiptId, onClose }) {
  const [receipt, setReceipt] = useState(null)
  const [error, setError] = useState(null)
  const [printing, setPrinting] = useState(false)
  useEffect(() => { let active = true; get(`/receipts/${receiptId}`).then((body) => { if (active) setReceipt(body.data) }).catch((requestError) => { if (active) setError(requestError) }); return () => { active = false } }, [receiptId])
  const print = async () => { if (!receipt || printing) return; setPrinting(true); setError(null); try { const body = await post(`/receipts/${receipt.id}/printed`, {}); setReceipt(body.data); window.print() } catch (requestError) { setError(requestError) } finally { setPrinting(false) } }
  return <Modal title="Receipt preview" onClose={() => !printing && onClose()}><ErrorMessage error={error} />{!receipt ? !error && <div className="a-empty">Loading receipt…</div> : <><article className="cashier-receipt"><header><strong>{receipt.restaurant_name}</strong><span>Porok 7, Nueva Estrella, Bien Unido, Bohol</span></header><div className="cashier-receipt-meta"><span>{receipt.receipt_number}</span><span>{receipt.transaction_number} · Table {receipt.table_number}</span><time>{dateTime(receipt.paid_at)}</time></div><div className="cashier-receipt-items">{receipt.items.map((item, index) => <div key={`${item.order_number}-${index}`}><span>{item.quantity}×</span><span>{item.name}</span><strong>{currency(item.subtotal)}</strong></div>)}</div><dl><div><dt>Subtotal</dt><dd>{currency(receipt.subtotal)}</dd></div><div className="cashier-receipt-total"><dt>Total</dt><dd>{currency(receipt.total_amount)}</dd></div></dl><div className="cashier-receipt-payments">{receipt.payments.map((payment) => <div key={payment.payment_number}><strong>Paid via {payment.payment_method === 'gcash' ? 'GCash' : 'Cash'}</strong>{payment.reference_number && <span>Reference {payment.reference_number}</span>}{Number(payment.change_amount || 0) > 0 && <span>Change {currency(payment.change_amount)}</span>}</div>)}</div><footer>Thank you, kain tayo ulit!</footer></article><div className="a-form-footer cashier-receipt-actions"><Button onClick={onClose}>Close</Button><Button primary disabled={printing} onClick={print}>{printing ? 'Preparing print…' : 'Print receipt'}</Button></div></>}</Modal>
}

export function CashierReportsLegacy() {
  const [period, setPeriod] = useState('today')
  const today = new Date().toISOString().slice(0, 10)
  const start = new Date(`${today}T00:00:00Z`)
  if (period === 'week') start.setUTCDate(start.getUTCDate() - 6)
  if (period === 'month') start.setUTCDate(start.getUTCDate() - 29)
  const from = start.toISOString().slice(0, 10)
  const query = `from=${from}&to=${today}`
  const resource = useData(`/cashier/reports/sales?${query}`, `/cashier/reports/menu-items?${query}`, `/cashier/reports/payments/summary?${query}`)
  const sales = resource.data?.[0]?.data
  const items = resource.data?.[1]?.data || []
  const payments = resource.data?.[2]?.data
  return <><PageHeading eyebrow="Shift performance" title="Reports"><Tabs values={[["today", 'Today'], ["week", '7 days'], ["month", '30 days']]} value={period} onChange={setPeriod} /></PageHeading><DataState resource={resource}>{sales && <><div className="a-metrics"><Metric icon="reports" label="Total sales" value={currency(sales.total_sales)} note={`${from} – ${today} UTC`} /><Metric icon="transactions" label="Paid transactions" value={sales.transaction_count} note="Completed bills" /><Metric icon="coffee" label="Items sold" value={sales.item_quantity} note="Accepted order items" /><Metric icon="inventory" label="Average value" value={currency(sales.average_transaction_value)} note="Per paid transaction" /></div><div className="a-columns"><Panel title="Best-selling items">{items.length ? <div className="a-best-sellers">{items.slice(0, 7).map((item) => <div key={item.menu_item_id}><div><span>{item.name}</span><strong>{item.quantity_sold} sold</strong></div><div className="a-progress"><span style={{ width: `${item.quantity_sold / Math.max(1, items[0].quantity_sold) * 100}%` }} /></div></div>)}</div> : <Empty>No sales in this period.</Empty>}</Panel><Panel title="Payment methods"><dl className="a-summary-list"><div><dt>Payments</dt><dd>{payments.payment_count}</dd></div><div><dt>Total received</dt><dd>{currency(payments.total_paid)}</dd></div>{Object.entries(payments.payment_methods || {}).map(([method, total]) => <div key={method}><dt>{method === 'gcash' ? 'GCash' : 'Cash'}</dt><dd>{currency(total)}</dd></div>)}</dl></Panel></div></>}</DataState></>
}

export function CashierReports() {
  return <SharedReportsPage scope="cashier" />
}

export function CashierTransactions() {
  const today = new Date().toISOString().slice(0, 10)
  const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10)
  const [view, setView] = useState('today')
  const [draft, setDraft] = useState({ search: '', status: '' })
  const [filters, setFilters] = useState(draft)
  const [page, setPage] = useState(1)
  const [bill, setBill] = useState(null)
  const [receiptId, setReceiptId] = useState(null)
  const [error, setError] = useState(null)
  const dates = view === 'today' ? { from: today, to: today } : { to: yesterday }
  const resource = useData(`/cashier/reports/transactions?${queryString({ ...filters, ...dates, page })}`)
  const result = resource.data?.[0]
  const openBill = async (row) => { setError(null); try { setBill((await get(`/cashier/transactions/${row.id}/bill`)).data) } catch (requestError) { setError(requestError) } }
  const changeView = (next) => { setView(next); setPage(1) }

  return <><PageHeading eyebrow="Payment records" title={view === 'today' ? 'Transactions today' : 'Transaction history'} description={view === 'today' ? 'Open bills and payments created today (UTC).' : 'Bills and payments from yesterday and earlier.'} /><Tabs values={[["today", 'Today'], ["history", 'Transaction history']]} value={view} onChange={changeView} /><ErrorMessage error={error} /><form className="a-filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters({ ...draft }) }}><Field label="Search transactions" placeholder="Order, table, or transaction ID" value={draft.search} onChange={(event) => setDraft({ ...draft, search: event.target.value })} /><Field label="Status"><select value={draft.status} onChange={(event) => setDraft({ ...draft, status: event.target.value })}><option value="">All statuses</option><option value="open">Open</option><option value="partially_paid">Partially paid</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select></Field><Button icon="search">Apply</Button></form><DataState resource={resource}><Panel title={view === 'today' ? 'Today’s transactions' : 'Previous transactions'} className="a-table-panel"><Table headings={['Transaction', 'Order', 'Table', 'Total', 'Paid', 'Status', 'Action']} rows={(result?.data || []).map((row) => <tr key={row.id}><td><strong>{row.transaction_number}</strong></td><td>{row.order_numbers?.join(', ') || '—'}</td><td>Table {row.table?.table_number || '—'}</td><td>{currency(row.total_amount)}</td><td>{currency(row.paid_amount)}</td><td><Badge value={row.status} /></td><td><div className="a-actions"><Button onClick={() => openBill(row)}>{row.status === 'paid' ? 'View bill' : 'Open bill'}</Button>{row.receipt && <Button onClick={() => setReceiptId(row.receipt.id)}>Receipt</Button>}</div></td></tr>)} empty={view === 'today' ? 'No transactions have been opened today.' : 'No previous transactions found.'} /><Pagination meta={result?.meta} onPage={setPage} /></Panel></DataState>{bill && <PaymentDialog initialBill={bill} onClose={() => setBill(null)} onPaid={(receipt) => { setBill(null); resource.refresh(); if (receipt?.id) setReceiptId(receipt.id) }} />}{receiptId && <ReceiptPreview receiptId={receiptId} onClose={() => { setReceiptId(null); resource.refresh() }} />}</>
}
