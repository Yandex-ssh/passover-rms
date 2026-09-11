import { useEffect, useMemo, useState } from 'react'
import { ApiError, get, post } from '../api'
import { SERVICE_POLL_INTERVAL } from '../api/usePollingData'
import './customer.css'

const unwrap = (body) => body?.data ?? body
const terminalStatuses = ['completed', 'rejected', 'cancelled']
const steps = [
  { status: 'pending', title: 'Pending', text: 'Waiting for cashier confirmation' },
  { status: 'confirmed', title: 'Confirmed', text: 'Your order was confirmed' },
  { status: 'preparing', title: 'Preparing', text: 'The kitchen is preparing your order' },
  { status: 'completed', title: 'Completed', text: 'Your order is ready to be served' },
]

function currency(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0))
}

function requestError(error) {
  if (!(error instanceof ApiError)) return 'We could not connect to the cafe. Check your connection and try again.'
  if (error.status === 503) return 'The menu assistant is temporarily unavailable. You can still place your order.'
  const validationMessage = Object.values(error.validationErrors || {}).flat()[0]
  return validationMessage || error.message
}

function Glyph({ name, size = 22 }) {
  const paths = {
    arrow: <path d="m15 18-6-6 6-6" />,
    bag: <><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 9V6a3 3 0 0 1 6 0v3" /></>,
    bot: <><rect x="4" y="7" width="16" height="12" rx="4" /><path d="M12 3v4M8 12h.01M16 12h.01M9 16h6" /></>,
    check: <path d="m5 12 4 4L19 6" />,
    chevron: <path d="m9 18 6-6-6-6" />,
    coffee: <><path d="M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5V9Z" /><path d="M16 11h2a2 2 0 0 1 0 4h-2M7 22h10M8 3c1 1-1 2 0 3M12 3c1 1-1 2 0 3" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></>,
    send: <path d="m3 11 18-8-8 18-2-8-8-2Zm8 2 4-4" />,
    spark: <><path d="m12 3 1.3 3.7L17 8l-3.7 1.3L12 13l-1.3-3.7L7 8l3.7-1.3L12 3Z" /><path d="m18 14 .8 2.2L21 17l-2.2.8L18 20l-.8-2.2L15 17l2.2-.8L18 14Z" /></>,
  }
  return <svg aria-hidden="true" viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{paths[name]}</svg>
}

function Brand() {
  return <div className="c-brand"><span className="c-brand-mark"><Glyph name="coffee" /></span><span>PASS-OVER <b>CAFE</b></span></div>
}

function TableLabel({ table }) {
  return <span className="c-table-label">Table {table?.table_number || '—'}</span>
}

function CustomerHeader({ table, title, onBack, cartCount = 0, onCart }) {
  return <header className="c-header">
    <div className="c-header-side">{onBack && <button className="c-icon-button" type="button" aria-label="Go back" onClick={onBack}><Glyph name="arrow" /></button>}</div>
    <div className="c-header-center">{title ? <><strong>{title}</strong><TableLabel table={table} /></> : <Brand />}</div>
    <div className="c-header-side c-header-right">{onCart && <button className="c-icon-button c-cart-button" type="button" aria-label={`Open cart with ${cartCount} items`} onClick={onCart}><Glyph name="bag" />{cartCount > 0 && <span>{cartCount}</span>}</button>}</div>
  </header>
}

function MenuVisual({ item, large = false }) {
  const [failed, setFailed] = useState(false)
  const source = item?.image && (/^(https?:|data:|blob:|\/)/.test(item.image) ? item.image : `/${item.image}`)
  return <div className={`c-food-visual ${large ? 'is-large' : ''}`}>
    {source && !failed ? <img src={source} alt="" onError={() => setFailed(true)} /> : <div className="c-plate" aria-hidden="true"><span /><i>{item?.name?.slice(0, 1) || 'P'}</i></div>}
  </div>
}

function Notice({ children, error = false }) {
  return <div className={`c-notice ${error ? 'is-error' : ''}`}>{children}</div>
}

function WelcomeScreen({ table, loading, error, onContinue }) {
  return <main className="c-welcome">
    <Brand />
    <div className="c-welcome-copy"><span>Welcome!</span><h1>Table {table?.table_number || '—'}</h1><p>Thanks for dining with us.<br />Scan, choose, and enjoy your meal.</p></div>
    <div className="c-table-scene" aria-hidden="true"><div className="c-plant">✦</div><div className="c-chair c-chair-left" /><div className="c-scene-table"><span /></div><div className="c-chair c-chair-right" /></div>
    {error && <Notice error>{requestError(error)}</Notice>}
    {!loading && table && !table.accepts_orders && <Notice>Your table is not accepting orders yet. You may browse the menu while a staff member opens the table.</Notice>}
    <button className="c-primary c-wide" type="button" disabled={loading || !table} onClick={onContinue}><Glyph name="coffee" />{loading ? 'Preparing your menu…' : 'View menu'}</button>
  </main>
}

function MenuScreen({ table, items, categories, cartCount, onBack, onCart, onOpenItem, onQuickAdd }) {
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('all')
  const availableItems = useMemo(() => items.filter((item) => item.is_available !== false && item.category?.is_active !== false), [items])
  const filtered = useMemo(() => availableItems.filter((item) => {
    const matchesCategory = category === 'all' || String(item.category?.id || item.category_id) === category
    const searchable = `${item.name} ${item.description || ''} ${item.category?.name || ''}`.toLowerCase()
    return matchesCategory && searchable.includes(query.trim().toLowerCase())
  }), [availableItems, category, query])
  const activeCategories = categories.filter((entry) => entry.is_active !== false)
  const groups = category === 'all' && !query.trim()
    ? activeCategories.map((entry) => ({ ...entry, items: filtered.filter((item) => String(item.category?.id || item.category_id) === String(entry.id)) })).filter((entry) => entry.items.length)
    : [{ id: category, name: category === 'all' ? 'Menu' : activeCategories.find((entry) => String(entry.id) === category)?.name || 'Menu', items: filtered }]

  return <>
    <CustomerHeader table={table} onBack={onBack} cartCount={cartCount} onCart={onCart} />
    <main className="c-content c-menu-screen">
      <div className="c-menu-intro"><div><span className="c-kicker">Made for your table</span><h1>What are you craving?</h1></div><TableLabel table={table} /></div>
      <label className="c-search"><Glyph name="search" size={20} /><input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search the menu" aria-label="Search the menu" /></label>
      <div className="c-category-tabs" role="tablist" aria-label="Menu categories">
        <button className={category === 'all' ? 'is-active' : ''} type="button" onClick={() => setCategory('all')}>All</button>
        {activeCategories.map((entry) => <button className={category === String(entry.id) ? 'is-active' : ''} type="button" key={entry.id} onClick={() => setCategory(String(entry.id))}>{entry.name}</button>)}
      </div>
      {groups.map((group) => <section className="c-menu-group" key={group.id}><div className="c-section-heading"><h2>{group.name}</h2><span>{group.items.length} {group.items.length === 1 ? 'item' : 'items'}</span></div><div className="c-menu-list">{group.items.map((item) => <article className="c-menu-card" key={item.id}><button className="c-item-main" type="button" onClick={() => onOpenItem(item)}><MenuVisual item={item} /><span><strong>{item.name}</strong><small>{item.description || 'Freshly prepared by our kitchen.'}</small><b>{currency(item.price)}</b></span></button><button className="c-add-button" type="button" aria-label={`Add ${item.name} to cart`} onClick={() => onQuickAdd(item)}>+</button></article>)}</div></section>)}
      {!groups.length && <div className="c-empty"><Glyph name="search" size={30} /><strong>No menu items found</strong><span>Try a different search or category.</span></div>}
    </main>
  </>
}

function Quantity({ value, onChange }) {
  return <div className="c-quantity"><button type="button" aria-label="Decrease quantity" onClick={() => onChange(Math.max(1, value - 1))}>−</button><strong>{value}</strong><button type="button" aria-label="Increase quantity" onClick={() => onChange(value + 1)}>+</button></div>
}

function ItemDetails({ item, table, onBack, onAdd }) {
  const [quantity, setQuantity] = useState(1)
  const [instruction, setInstruction] = useState('')
  return <>
    <CustomerHeader table={table} title="Item details" onBack={onBack} />
    <main className="c-content c-details">
      <MenuVisual item={item} large />
      <div className="c-details-title"><div><span className="c-kicker">{item.category?.name || 'Menu item'}</span><h1>{item.name}</h1></div><strong>{currency(item.price)}</strong></div>
      <p>{item.description || 'Freshly prepared by our kitchen for your table.'}</p>
      <dl className="c-item-facts"><div><dt>Availability</dt><dd><span className="c-available-dot" />Available</dd></div><div><dt>Category</dt><dd>{item.category?.name || 'Menu'}</dd></div></dl>
      <label className="c-field">Special instruction <span>Optional</span><textarea maxLength={255} value={instruction} onChange={(event) => setInstruction(event.target.value)} placeholder="e.g. Less spicy, no onions" /></label>
    </main>
    <div className="c-bottom-bar"><Quantity value={quantity} onChange={setQuantity} /><button className="c-primary" type="button" onClick={() => onAdd(item, quantity, instruction)}>Add · {currency(Number(item.price) * quantity)}</button></div>
  </>
}

function CartLine({ item, onChange, review = false }) {
  return <article className="c-cart-line"><MenuVisual item={item} /><div className="c-cart-line-copy"><strong>{item.name}</strong><span>{currency(item.price)} each</span>{item.special_instruction && <small>{item.special_instruction}</small>}{!review && <Quantity value={item.quantity} onChange={onChange} />}</div><b>{review ? `×${item.quantity}` : currency(Number(item.price) * item.quantity)}</b></article>
}

function CartScreen({ table, cart, note, subtotal, onBack, onChange, onNote, onReview }) {
  return <>
    <CustomerHeader table={table} title="Your cart" onBack={onBack} />
    <main className="c-content c-cart-screen">
      {!cart.length ? <div className="c-empty c-cart-empty"><Glyph name="bag" size={34} /><strong>Your cart is empty</strong><span>Add an item from the menu to get started.</span><button className="c-secondary" type="button" onClick={onBack}>Browse menu</button></div> : <>
        <div className="c-cart-lines">{cart.map((item) => <CartLine key={item.id} item={item} onChange={(quantity) => onChange(item.id, quantity)} />)}</div>
        <label className="c-field">Note to the kitchen <span>Optional</span><textarea maxLength={500} value={note} onChange={(event) => onNote(event.target.value)} placeholder="e.g. Less spicy, no onions" /></label>
        <div className="c-totals"><div><span>Subtotal</span><strong>{currency(subtotal)}</strong></div><small>The cashier will confirm your final bill.</small></div>
      </>}
    </main>
    {!!cart.length && <div className="c-bottom-bar"><div className="c-bar-total"><span>Total</span><strong>{currency(subtotal)}</strong></div><button className="c-primary" type="button" onClick={onReview}>Review order <Glyph name="chevron" size={19} /></button></div>}
  </>
}

function ReviewScreen({ table, cart, note, subtotal, busy, error, onBack, onSubmit }) {
  return <>
    <CustomerHeader table={table} title="Review order" onBack={onBack} />
    <main className="c-content c-review">
      <div className="c-review-card"><div className="c-review-table"><span>Ordering for</span><strong>Table {table?.table_number || '—'}</strong></div>{cart.map((item) => <CartLine key={item.id} item={item} review />)}</div>
      {note && <section className="c-note-card"><span>Note to the kitchen</span><p>{note}</p></section>}
      <div className="c-totals c-review-total"><div><span>Subtotal</span><strong>{currency(subtotal)}</strong></div><div className="c-total-row"><span>Total</span><strong>{currency(subtotal)}</strong></div><small>Payment is collected by the cashier after your meal.</small></div>
      {error && <Notice error>{requestError(error)}</Notice>}
    </main>
    <div className="c-bottom-bar c-single-action"><button className="c-primary c-wide" type="button" disabled={busy} onClick={onSubmit}>{busy ? 'Submitting order…' : 'Submit order'}</button></div>
  </>
}

function ConfirmationScreen({ table, order, status, onStatus, onOrderMore }) {
  const waiting = status === 'pending'
  return <main className="c-confirmation">
    <div className="c-confirmation-top"><Brand /><TableLabel table={table} /></div>
    <div className="c-success-mark"><Glyph name="check" size={52} /></div>
    <span className="c-kicker">Order received</span><h1>{waiting ? 'Order submitted!' : 'Your order is moving.'}</h1><p>{waiting ? 'Your order has been sent and is waiting for cashier confirmation.' : 'Open the order status to see the latest update.'}</p>
    <div className="c-order-number"><span>Order number</span><strong>{order.order_number || order.id}</strong></div>
    <button className="c-primary c-wide" type="button" onClick={onStatus}>View order status</button>
    <button className="c-text-button" type="button" onClick={onOrderMore}>Add another order</button>
  </main>
}

function formatTime(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(new Date(value))
}

function StatusScreen({ table, order, status, error, onBack, onOrderMore }) {
  const currentIndex = steps.findIndex((step) => step.status === status)
  const exceptional = status === 'rejected' || status === 'cancelled'
  return <>
    <CustomerHeader table={table} title="Order status" onBack={onBack} />
    <main className="c-content c-status-screen">
      <div className="c-status-number"><span>Order number</span><strong>{order.order_number || order.id}</strong></div>
      {exceptional && <Notice error>{status === 'rejected' ? 'The cashier could not accept this order. Please speak with a staff member.' : 'This order was cancelled.'}</Notice>}
      <ol className={`c-timeline ${exceptional ? 'is-stopped' : ''}`}>{steps.map((step, index) => {
        const reached = !exceptional && currentIndex >= index
        const current = !exceptional && currentIndex === index
        const timestamp = index === 0 ? order.submitted_at : index === 1 ? order.confirmed_at : reached ? order.updated_at : null
        return <li className={`${reached ? 'is-reached' : ''} ${current ? 'is-current' : ''}`} key={step.status}><span className="c-timeline-dot">{reached && <Glyph name="check" size={13} />}</span><div><strong>{step.title}</strong><p>{step.text}</p></div><time>{formatTime(timestamp)}</time></li>
      })}</ol>
      {error && <Notice error>{error}</Notice>}
      <Notice>{terminalStatuses.includes(status) ? 'This is the latest status for your order.' : 'This page updates automatically when your order changes.'}</Notice>
      <button className="c-secondary c-wide" type="button" onClick={onOrderMore}>Order more</button>
    </main>
  </>
}

function Assistant({ open, onClose }) {
  const [question, setQuestion] = useState('')
  const [messages, setMessages] = useState([{ role: 'assistant', text: 'Hi! I’m your Pass-over menu assistant. What can I help you find?' }])
  const [busy, setBusy] = useState(false)

  const ask = async (event, prompt) => {
    event?.preventDefault()
    const nextQuestion = (prompt || question).trim()
    if (!nextQuestion || busy) return
    setQuestion('')
    setMessages((current) => [...current, { role: 'user', text: nextQuestion }])
    setBusy(true)
    try {
      const answer = unwrap(await post('/customer/chatbot', { question: nextQuestion })).answer
      setMessages((current) => [...current, { role: 'assistant', text: answer }])
    } catch (error) {
      setMessages((current) => [...current, { role: 'assistant', text: requestError(error) }])
    } finally {
      setBusy(false)
    }
  }

  if (!open) return null
  return <div className="c-assistant-overlay" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}><section className="c-assistant" role="dialog" aria-modal="true" aria-labelledby="assistant-title"><div className="c-assistant-header"><span className="c-bot-avatar"><Glyph name="bot" /></span><div><h2 id="assistant-title">AI menu assistant</h2><span><i /> Menu and ordering help</span></div><button className="c-icon-button" type="button" aria-label="Close assistant" onClick={onClose}>×</button></div><div className="c-messages">{messages.map((message, index) => <div className={`c-message is-${message.role}`} key={`${message.role}-${index}`}>{message.role === 'assistant' && <span><Glyph name="bot" size={17} /></span>}<p>{message.text}</p></div>)}{busy && <div className="c-message is-assistant"><span><Glyph name="bot" size={17} /></span><p>Finding an answer…</p></div>}</div><div className="c-prompts">{['What drinks are available?', 'Unsa inyong available drinks?', 'Pwede GCash?', 'How do I order?'].map((prompt) => <button type="button" disabled={busy} key={prompt} onClick={() => ask(null, prompt)}>{prompt}</button>)}</div><form className="c-assistant-form" onSubmit={ask}><input value={question} maxLength={500} onChange={(event) => setQuestion(event.target.value)} placeholder="Ask about the menu…" aria-label="Ask the menu assistant" /><button type="submit" aria-label="Send message" disabled={busy || !question.trim()}><Glyph name="send" size={20} /></button></form></section></div>
}

export default function CustomerOrder({ token }) {
  const trackingKey = `passover-order-tracking:${token}`
  const cartKey = `passover-cart:${token}`
  const [table, setTable] = useState(null)
  const [items, setItems] = useState([])
  const [categories, setCategories] = useState([])
  const [cart, setCart] = useState(() => { try { return JSON.parse(sessionStorage.getItem(cartKey) || '[]') } catch { return [] } })
  const [note, setNote] = useState('')
  const [selected, setSelected] = useState(null)
  const [submitted, setSubmitted] = useState(() => { try { return JSON.parse(sessionStorage.getItem(trackingKey) || 'null') } catch { return null } })
  const [status, setStatus] = useState(() => submitted?.status || 'pending')
  const [screen, setScreen] = useState(() => submitted ? 'confirmation' : 'welcome')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  const [trackingError, setTrackingError] = useState(null)
  const [assistantOpen, setAssistantOpen] = useState(false)

  useEffect(() => {
    Promise.all([get(`/customer/tables/${encodeURIComponent(token)}`), get('/categories'), get('/menu-items')])
      .then(([tableResult, categoryResult, itemResult]) => {
        setTable(unwrap(tableResult))
        setCategories(unwrap(categoryResult))
        setItems(unwrap(itemResult))
      })
      .catch(setError)
      .finally(() => setLoading(false))
  }, [token])

  useEffect(() => {
    sessionStorage.setItem(cartKey, JSON.stringify(cart))
  }, [cart, cartKey])

  useEffect(() => {
    if (!submitted?.tracking_token) return undefined
    let active = true
    let timer
    let pending = false
    const check = async () => {
      if (pending) return
      pending = true
      try {
        const current = unwrap(await get(`/customer/orders/${submitted.tracking_token}/status`))
        if (!active) return
        setStatus(current.status)
        setSubmitted((previous) => {
          const next = { ...previous, ...current }
          sessionStorage.setItem(trackingKey, JSON.stringify(next))
          return next
        })
        setTrackingError(null)
        if (terminalStatuses.includes(current.status)) clearInterval(timer)
      } catch (trackingRequestError) {
        if (active) setTrackingError(trackingRequestError instanceof ApiError && trackingRequestError.status === 404 ? 'Order tracking is no longer available.' : 'The latest status could not be loaded. We will keep trying.')
      } finally { pending = false }
    }
    timer = setInterval(check, SERVICE_POLL_INTERVAL)
    check()
    return () => { active = false; clearInterval(timer) }
  }, [submitted?.tracking_token, trackingKey])

  const cartCount = cart.reduce((sum, item) => sum + item.quantity, 0)
  const subtotal = cart.reduce((sum, item) => sum + Number(item.price || 0) * item.quantity, 0)
  const add = (item, quantity = 1, specialInstruction = '') => {
    setCart((current) => {
      const existing = current.find((line) => line.id === item.id)
      if (!existing) return [...current, { ...item, quantity, special_instruction: specialInstruction }]
      return current.map((line) => line.id === item.id ? { ...line, quantity: line.quantity + quantity, special_instruction: specialInstruction || line.special_instruction } : line)
    })
    setSelected(null)
    setScreen('menu')
  }
  const changeQuantity = (id, quantity) => setCart((current) => quantity < 1 ? current.filter((line) => line.id !== id) : current.map((line) => line.id === id ? { ...line, quantity } : line))
  const orderMore = () => {
    sessionStorage.removeItem(trackingKey)
    setSubmitted(null)
    setStatus('pending')
    setTrackingError(null)
    setScreen('menu')
  }
  const submit = async () => {
    if (!cart.length || submitting) return
    setSubmitting(true)
    setError(null)
    try {
      const result = unwrap(await post(`/customer/tables/${encodeURIComponent(token)}/orders`, {
        customer_note: note || null,
        items: cart.map((item) => ({ menu_item_id: item.id, quantity: item.quantity, special_instruction: item.special_instruction || null })),
      }))
      setSubmitted(result)
      setStatus(result.status || 'pending')
      sessionStorage.setItem(trackingKey, JSON.stringify(result))
      setCart([])
      setNote('')
      setScreen('confirmation')
    } catch (submitError) {
      setError(submitError)
    } finally {
      setSubmitting(false)
    }
  }

  let content
  if (screen === 'welcome') content = <WelcomeScreen table={table} loading={loading} error={error} onContinue={() => setScreen('menu')} />
  if (screen === 'menu') content = <MenuScreen table={table} items={items} categories={categories} cartCount={cartCount} onBack={() => setScreen('welcome')} onCart={() => setScreen('cart')} onOpenItem={(item) => { setSelected(item); setScreen('details') }} onQuickAdd={add} />
  if (screen === 'details' && selected) content = <ItemDetails key={selected.id} item={selected} table={table} onBack={() => setScreen('menu')} onAdd={add} />
  if (screen === 'cart') content = <CartScreen table={table} cart={cart} note={note} subtotal={subtotal} onBack={() => setScreen('menu')} onChange={changeQuantity} onNote={setNote} onReview={() => setScreen('review')} />
  if (screen === 'review') content = <ReviewScreen table={table} cart={cart} note={note} subtotal={subtotal} busy={submitting} error={error} onBack={() => setScreen('cart')} onSubmit={submit} />
  if (screen === 'confirmation' && submitted) content = <ConfirmationScreen table={table} order={submitted} status={status} onStatus={() => setScreen('status')} onOrderMore={orderMore} />
  if (screen === 'status' && submitted) content = <StatusScreen table={table} order={submitted} status={status} error={trackingError} onBack={() => setScreen('confirmation')} onOrderMore={orderMore} />

  const hasBottomBar = ['details', 'cart', 'review'].includes(screen) && (screen !== 'cart' || cart.length > 0)

  return <div className="customer-app"><div className="c-phone">{content}</div>{screen !== 'welcome' && <button className={`c-assistant-button ${hasBottomBar ? 'has-bottom-bar' : ''}`} type="button" aria-label="Open AI menu assistant" onClick={() => setAssistantOpen(true)}><Glyph name="spark" size={14} /><Glyph name="bot" size={27} /></button>}<Assistant open={assistantOpen} onClose={() => setAssistantOpen(false)} /></div>
}
