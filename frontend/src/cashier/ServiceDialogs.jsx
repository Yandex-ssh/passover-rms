import { useState } from 'react'
import { createPortal } from 'react-dom'
import { post } from '../api'
import { currency, dateTime, useData } from '../admin/data'
import { Button, DataState, ErrorMessage, Field, Modal } from '../admin/shared'
import './service-dialogs.css'

export function OrderEntry({ onClose, onCreated }) {
  const resource = useData('/cashier/tables', '/cashier/menu-items', '/categories')
  const [tableId, setTableId] = useState('')
  const [search, setSearch] = useState('')
  const [lines, setLines] = useState({})
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const tables = (resource.data?.[0]?.data || []).filter((table) => table.status === 'available' || table.active_session)
  const activeCategories = new Set((resource.data?.[2]?.data || []).filter((category) => category.is_active).map((category) => category.id))
  const items = (resource.data?.[1]?.data || []).filter((item) => item.is_available && activeCategories.has(item.category_id) && item.inventory?.quantity > 0)
  const selected = items.filter((item) => Number(lines[item.id]?.quantity) > 0)
  const total = selected.reduce((sum, item) => sum + Number(item.price) * Number(lines[item.id].quantity), 0)
  const update = (id, changes) => setLines((current) => ({ ...current, [id]: { ...current[id], ...changes } }))
  const submit = async (event) => {
    event.preventDefault()
    if (busy || !selected.length) return
    setBusy(true); setError(null)
    try {
      const result = await post(`/cashier/tables/${tableId}/orders`, {
        customer_note: note || null,
        items: selected.map((item) => ({ menu_item_id: item.id, quantity: Number(lines[item.id].quantity), special_instruction: lines[item.id].instruction || null })),
      })
      onCreated(result.data)
    } catch (requestError) {
      const detail = Object.values(requestError.validationErrors || {}).flat()[0]
      setError({ message: detail || requestError.message })
    } finally { setBusy(false) }
  }
  return <Modal title="New table order" onClose={() => !busy && onClose()}><ErrorMessage error={error} /><DataState resource={resource}>
    <form className="a-form" onSubmit={submit}>
      <Field label="Table"><select required value={tableId} onChange={(event) => setTableId(event.target.value)}><option value="">Select a table</option>{tables.map((table) => <option value={table.id} key={table.id}>Table {table.table_number}</option>)}</select></Field>
      <Field label="Find menu items" type="search" value={search} onChange={(event) => setSearch(event.target.value)} />
      <div className="cashier-entry-items">{items.filter((item) => item.name.toLowerCase().includes(search.toLowerCase())).map((item) => <div className="cashier-entry-item" key={item.id}>
        <div><strong>{item.name}</strong><small>{currency(item.price)} · {item.inventory.quantity} available</small></div>
        <Field label={`Quantity: ${item.name}`} type="number" min="0" max={Math.min(99, item.inventory.quantity)} step="1" value={lines[item.id]?.quantity ?? 0} onChange={(event) => update(item.id, { quantity: event.target.value })} />
        {Number(lines[item.id]?.quantity) > 0 && <Field label={`Instructions: ${item.name}`} maxLength={255} value={lines[item.id]?.instruction || ''} onChange={(event) => update(item.id, { instruction: event.target.value })} />}
      </div>)}</div>
      {!items.length && <p>No items are currently available to order.</p>}
      <Field label="Kitchen note"><textarea maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} /></Field>
      <strong>Total: {currency(total)}</strong><p className="a-muted">The order will be pending. Confirm it from the order list to send it to the kitchen.</p>
      <div className="a-form-footer"><Button type="button" disabled={busy} onClick={onClose}>Cancel</Button><Button primary disabled={busy || !tableId || !selected.length}>{busy ? 'Submitting…' : 'Create order'}</Button></div>
    </form>
  </DataState></Modal>
}

export function CloseSession({ table, onClose, onClosed }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const close = async () => {
    setBusy(true); setError(null)
    try {
      await post(`/cashier/table-sessions/${table.active_session.id}/close`, {})
      onClosed()
    } catch (requestError) {
      setError({ message: Object.values(requestError.validationErrors || {}).flat()[0] || requestError.message })
    } finally { setBusy(false) }
  }
  return <Modal title={`Close dining session · Table ${table.table_number}`} onClose={() => !busy && onClose()}><ErrorMessage error={error} /><p>Close this group’s dining session and make the table available for new guests. All orders must be completed or rejected and all bills paid.</p><div className="a-form-footer"><Button disabled={busy} onClick={onClose}>Keep open</Button><Button primary disabled={busy} onClick={close}>{busy ? 'Closing…' : 'Close session'}</Button></div></Modal>
}

function SlipContent({ slip }) {
  return <article className="cashier-kitchen-slip"><div className="cashier-slip-heading"><strong>PASS-OVER CAFE</strong><h2>Kitchen order slip</h2></div><p><strong>Table {slip.table_number}</strong><br />{slip.ticket_number}<br />{slip.order_number}<br />{dateTime(slip.order_time)}</p>
    <ul>{slip.items.map((item, index) => <li key={index}><strong>{item.quantity} × {item.menu_item_name}</strong>{item.special_instruction && <p>{item.special_instruction}</p>}</li>)}</ul>
    {slip.customer_note && <section><strong>Kitchen note</strong><p>{slip.customer_note}</p></section>}
  </article>
}

export function KitchenSlipPreview({ ticketId, onClose }) {
  const resource = useData(`/kitchen-order-slips/${ticketId}`)
  const slip = resource.data?.[0]?.data
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const print = async () => {
    if (!slip || busy) return
    setBusy(true); setError(null)
    try { await post(`/kitchen-order-slips/${ticketId}/printed`, {}); window.print() } catch (requestError) { setError(requestError) } finally { setBusy(false) }
  }
  return <Modal title="Kitchen order slip" onClose={() => !busy && onClose()}><ErrorMessage error={error} /><DataState resource={resource}>{slip && <>
    <SlipContent slip={slip} />
    {createPortal(<div className="cashier-print-document"><SlipContent slip={slip} /></div>, document.body)}
    <div className="a-form-footer"><Button disabled={busy} onClick={onClose}>Close</Button><Button primary disabled={busy} onClick={print}>{busy ? 'Preparing…' : 'Print kitchen slip'}</Button></div>
  </>}</DataState></Modal>
}
