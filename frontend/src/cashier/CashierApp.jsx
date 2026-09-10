import { useEffect, useRef, useState } from 'react'
import { get, post, put } from '../api'
import { Button, ErrorMessage, Field, Icon, IconButton, Modal, PageHeading } from '../admin/shared'
import { go } from '../admin/data'
import { CashierCategories, CashierDashboard, CashierMenu, CashierOrders, CashierReports, CashierTables, CashierTransactions } from './CashierPages'
import '../admin/admin.css'
import './cashier.css'

const navigation = [
  ['', 'Dashboard', 'dashboard'],
  ['tables', 'Tables & QR', 'tables'],
  ['categories', 'Categories', 'categories'],
  ['menu', 'Menu', 'menu'],
  ['orders', 'Orders', 'orders'],
  ['transactions', 'Transactions', 'transactions'],
  ['reports', 'Reports', 'reports'],
]

const pages = {
  '': CashierDashboard,
  tables: CashierTables,
  categories: CashierCategories,
  menu: CashierMenu,
  orders: CashierOrders,
  transactions: CashierTransactions,
  reports: CashierReports,
}

export default function CashierApp({ user, logout, path }) {
  const [profile, setProfile] = useState(user)
  const [profileOpen, setProfileOpen] = useState(false)
  const [open, setOpen] = useState(false)
  const [error, setError] = useState(null)
  const [signingOut, setSigningOut] = useState(false)
  const section = path.replace(/^\/cashier\/?/, '').replace(/\/$/, '')
  const entry = navigation.find(([key]) => key === section)
  const Page = pages[section]
  const navigate = (target) => { go(target); setOpen(false) }

  return <div className="admin-app cashier-app"><IncomingOrderAlert onOpenOrders={() => navigate('/cashier/orders?status=pending')} />{open && <button className="a-nav-scrim" onClick={() => setOpen(false)} aria-label="Close navigation" />}<aside className={`a-sidebar ${open ? 'is-open' : ''}`}><a className="a-brand" href="/cashier" onClick={(event) => { event.preventDefault(); navigate('/cashier') }}><Icon name="coffee" size={29} /><span>PASS-OVER CAFE<small>CASHIER WORKSPACE</small></span></a><nav aria-label="Cashier navigation">{navigation.map(([key, label, icon]) => <a key={key} href={`/cashier${key ? `/${key}` : ''}`} aria-current={section === key ? 'page' : undefined} onClick={(event) => { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); navigate(`/cashier${key ? `/${key}` : ''}`) }}><Icon name={icon} /><span>{label}</span></a>)}</nav><div className="a-sidebar-footer"><button type="button" className="a-profile a-profile-button" aria-label="Edit profile" onClick={() => setProfileOpen(true)}><div className="a-avatar">{profile.name?.[0]?.toUpperCase()}</div><div><strong>{profile.name}</strong><small>Cashier · Edit profile</small></div><Icon name="chevron" size={15} /></button><Button icon="logout" disabled={signingOut} onClick={async () => { setSigningOut(true); setError(null); try { await logout() } catch (e) { setError(e) } finally { setSigningOut(false) } }}>{signingOut ? 'Signing out…' : 'Sign out'}</Button></div></aside><div className="a-workspace"><header className="a-topbar"><div><IconButton className="a-menu-toggle" icon="bars" label="Toggle navigation" aria-expanded={open} onClick={() => setOpen(!open)} /><span>Cashier</span><span className="a-breadcrumb-slash">/</span><strong>{entry?.[1] || 'Page not found'}</strong></div><span className="a-topbar-note"><span /> Live service</span></header><main className="a-main"><ErrorMessage error={error} />{Page ? <Page key={section} /> : <><PageHeading eyebrow="404" title="Page not found" /><Button onClick={() => navigate('/cashier')}>Back to dashboard</Button></>}</main></div>{profileOpen && <ProfileDialog profile={profile} onSaved={setProfile} onClose={() => setProfileOpen(false)} />}</div>
}

function IncomingOrderAlert({ onOpenOrders }) {
  const knownIds = useRef(null)
  const [incoming, setIncoming] = useState([])

  useEffect(() => {
    let active = true
    const check = async () => {
      try {
        const response = await get('/cashier/orders')
        if (!active) return
        const orders = response?.data || []
        const nextIds = new Set(orders.map((order) => order.id))
        if (knownIds.current) {
          const newOrders = orders.filter((order) => !knownIds.current.has(order.id))
          if (newOrders.length) setIncoming(newOrders)
        }
        knownIds.current = nextIds
      } catch {
        // A temporary polling failure should not disrupt the cashier workspace.
      }
    }
    check()
    const timer = setInterval(check, 5000)
    return () => { active = false; clearInterval(timer) }
  }, [])

  useEffect(() => {
    if (!incoming.length) return undefined
    const timer = setTimeout(() => setIncoming([]), 12000)
    return () => clearTimeout(timer)
  }, [incoming])

  if (!incoming.length) return null
  const newest = incoming[incoming.length - 1]
  const table = newest?.table_session?.table?.table_number || newest?.table_session?.restaurant_table?.table_number || '—'
  return <section className="cashier-order-alert" role="status" aria-live="polite"><button type="button" className="cashier-order-alert-close" aria-label="Dismiss new order notification" onClick={() => setIncoming([])}>×</button><div className="cashier-order-alert-icon"><Icon name="orders" size={21} /></div><div><strong>{incoming.length === 1 ? 'New customer order' : `${incoming.length} new customer orders`}</strong><p>{incoming.length === 1 ? `${newest.order_number} · Table ${table}` : 'Orders are waiting for cashier confirmation.'}</p><Button primary onClick={() => { setIncoming([]); onOpenOrders() }}>View pending orders</Button></div></section>
}

function ProfileDialog({ profile, onSaved, onClose }) {
  const [details, setDetails] = useState({ name: profile.name, email: profile.email })
  const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [show, setShow] = useState({ current: false, password: false, confirmation: false })
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const toggle = (key) => setShow((current) => ({ ...current, [key]: !current[key] }))
  const passwordField = (label, key, visibleKey, confirmationLabel = false) => <Field label={label}><span className="password-field"><input type={show[visibleKey] ? 'text' : 'password'} autoComplete={key === 'current_password' ? 'current-password' : 'new-password'} minLength={key === 'current_password' ? undefined : 8} required value={passwords[key]} onChange={(event) => setPasswords({ ...passwords, [key]: event.target.value })} /><button type="button" className="password-toggle" aria-pressed={show[visibleKey]} aria-label={`${show[visibleKey] ? 'Hide' : 'Show'} ${confirmationLabel ? 'confirmed ' : ''}${key === 'current_password' ? 'current ' : ''}password`} onClick={() => toggle(visibleKey)}>{show[visibleKey] ? 'Hide' : 'Show'}</button></span></Field>

  return <Modal title="My profile" onClose={() => !busy && onClose()}><section className="cashier-profile-section"><h3>Account details</h3><p className="a-muted">Update the name and email used for this cashier account.</p><form className="a-form" onSubmit={async (event) => { event.preventDefault(); setBusy(true); setError(null); setNotice(''); try { const response = await put('/profile', details); onSaved(response.data); setNotice('Profile details updated.') } catch (e) { setError(e) } finally { setBusy(false) } }}><ErrorMessage error={error} /><Field label="Name" required maxLength={255} value={details.name} onChange={(event) => setDetails({ ...details, name: event.target.value })} /><Field label="Email" type="email" required maxLength={255} value={details.email} onChange={(event) => setDetails({ ...details, email: event.target.value })} /><Button primary disabled={busy}>{busy ? 'Saving…' : 'Save profile'}</Button></form></section><section className="cashier-profile-section"><h3>Change password</h3><p className="a-muted">Confirm the current password before setting a new one.</p><form className="a-form" onSubmit={async (event) => { event.preventDefault(); setBusy(true); setError(null); setNotice(''); try { await post('/profile/password', passwords); setPasswords({ current_password: '', password: '', password_confirmation: '' }); setNotice('Password updated successfully.') } catch (e) { setError(e) } finally { setBusy(false) } }}>{passwordField('Current password', 'current_password', 'current')}{passwordField('New password', 'password', 'password')}{passwordField('Confirm new password', 'password_confirmation', 'confirmation', true)}<Button primary disabled={busy}>{busy ? 'Updating…' : 'Change password'}</Button></form></section>{notice && <div className="cashier-profile-notice" role="status">{notice}</div>}</Modal>
}
