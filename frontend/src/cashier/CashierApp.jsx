import { useEffect, useRef, useState } from 'react'
import { get, post, put } from '../api'
import { Button, ErrorMessage, Field, Icon, IconButton, PageHeading, Panel, Tabs } from '../admin/shared'
import { go } from '../admin/data'
import { SERVICE_POLL_INTERVAL } from '../api/usePollingData'
import { CashierCategories, CashierDashboard, CashierMenu, CashierOrders, CashierReports, CashierTables, CashierTransactions } from './CashierPages'
import '../admin/admin.css'
import '../admin/dashboard-shell.css'
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
  profile: CashierProfile,
}

export default function CashierApp({ user, logout, path }) {
  const [profile, setProfile] = useState(user)
  const [open, setOpen] = useState(false)
  const [error, setError] = useState(null)
  const [signingOut, setSigningOut] = useState(false)
  const section = path.replace(/^\/cashier\/?/, '').replace(/\/$/, '')
  const entry = navigation.find(([key]) => key === section) || (section === 'profile' ? ['profile', 'Profile'] : null)
  const Page = pages[section]
  const visibleNavigation = section === 'profile' ? [['profile', 'Profile', 'users']] : navigation
  const navigate = (target) => { go(target); setOpen(false) }

  return <div className="admin-app cashier-app"><IncomingOrderAlert onOpenOrders={() => navigate('/cashier/orders?status=pending')} />{open && <button className="a-nav-scrim" onClick={() => setOpen(false)} aria-label="Close navigation" />}<aside className={`a-sidebar ${open ? 'is-open' : ''}`}><a className="a-brand" href="/cashier" onClick={(event) => { event.preventDefault(); navigate('/cashier') }}><Icon name="coffee" size={29} /><span>PASS-OVER CAFE<small>CASHIER WORKSPACE</small></span></a><nav aria-label="Cashier navigation">{visibleNavigation.map(([key, label, icon]) => <a key={key} href={`/cashier${key ? `/${key}` : ''}`} aria-current={section === key ? 'page' : undefined} onClick={(event) => { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); navigate(`/cashier${key ? `/${key}` : ''}`) }}><Icon name={icon} /><span>{label}</span></a>)}</nav><div className="a-sidebar-footer"><button type="button" className="a-profile a-profile-button" aria-label="Open profile" onClick={() => navigate('/cashier/profile')}><div className="a-avatar">{profile.name?.[0]?.toUpperCase()}</div><div><strong>{profile.name}</strong><small>Cashier</small></div><Icon name="chevron" size={15} /></button><Button icon="logout" disabled={signingOut} onClick={async () => { setSigningOut(true); setError(null); try { await logout() } catch (e) { setError(e) } finally { setSigningOut(false) } }}>{signingOut ? 'Signing out…' : 'Sign out'}</Button></div></aside><div className="a-workspace"><header className="a-topbar"><div><IconButton className="a-menu-toggle" icon="bars" label="Toggle navigation" aria-expanded={open} onClick={() => setOpen(!open)} /><span>Cashier</span><span className="a-breadcrumb-slash">/</span><strong>{entry?.[1] || 'Page not found'}</strong></div><span className="a-topbar-note"><span /> Live service</span></header><main className="a-main"><ErrorMessage error={error} />{Page ? <Page key={section} currentUser={profile} logout={logout} onProfileSaved={setProfile} /> : <><PageHeading eyebrow="404" title="Page not found" /><Button onClick={() => navigate('/cashier')}>Back to dashboard</Button></>}</main></div></div>
}

function CashierProfile({ currentUser: user, onProfileSaved, logout }) {
  const [view, setView] = useState('profile')
  const [signingOut, setSigningOut] = useState(false)
  const createdAt = user.created_at ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(user.created_at)) : '—'
  return <><PageHeading eyebrow="My account" title="Profile" description="View and manage your own account information." /><div className="profile-tabs profile-tabs-secondary cashier-profile-tabs"><Tabs values={[["profile", 'Profile'], ["edit", 'Edit profile']]} value={view} onChange={setView} /></div>{view === 'profile' ? <Panel className="profile-summary cashier-profile-summary"><div className="a-avatar profile-avatar">{user.name?.[0]?.toUpperCase()}</div><div className="profile-facts"><span className="a-eyebrow">Full name</span><strong>{user.name}</strong><span className="a-eyebrow">Role</span><strong>Cashier</strong><span className="a-eyebrow">Date created</span><strong>{createdAt}</strong></div><div className="profile-facts"><span className="a-eyebrow">Username / Email</span><strong>{user.email}</strong><span className="a-eyebrow">Account status</span><strong className="profile-status">● Active</strong></div><div className="a-actions"><Button primary onClick={() => setView('edit')}>Edit profile</Button><Button className="cashier-profile-logout" disabled={signingOut} onClick={async () => { setSigningOut(true); try { await logout() } finally { setSigningOut(false) } }}>{signingOut ? 'Signing out…' : 'Logout'}</Button></div></Panel> : <CashierProfileEditor user={user} onSaved={onProfileSaved} onCancel={() => setView('profile')} />}</>
}

function CashierProfileEditor({ user, onSaved, onCancel }) {
  const [details, setDetails] = useState({ name: user.name, email: user.email })
  const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [detailsError, setDetailsError] = useState(null)
  const [passwordError, setPasswordError] = useState(null)
  const [savingDetails, setSavingDetails] = useState(false)
  const [savingPassword, setSavingPassword] = useState(false)
  const saveDetails = async (event) => { event.preventDefault(); setSavingDetails(true); setDetailsError(null); try { const response = await put('/profile', { name: details.name, email: details.email }); onSaved(response.data) } catch (error) { setDetailsError(error) } finally { setSavingDetails(false) } }
  const savePassword = async (event) => { event.preventDefault(); setSavingPassword(true); setPasswordError(null); try { await post('/profile/password', passwords); setPasswords({ current_password: '', password: '', password_confirmation: '' }) } catch (error) { setPasswordError(error) } finally { setSavingPassword(false) } }
  return <div className="profile-editor"><form className="a-panel profile-edit-card" onSubmit={saveDetails}><ErrorMessage error={detailsError} /><div className="profile-photo"><div className="profile-photo-placeholder">↥</div><div><span className="a-eyebrow">Profile picture</span><label className="profile-upload">Upload new photo<input type="file" accept="image/*" aria-label="Upload a profile photo" /></label></div></div><div className="profile-edit-fields"><Field label="Full name" required value={details.name} onChange={(event) => setDetails({ ...details, name: event.target.value })} /><Field label="Email / Username" type="email" required value={details.email} onChange={(event) => setDetails({ ...details, email: event.target.value })} /></div><div className="a-actions"><Button primary disabled={savingDetails}>{savingDetails ? 'Saving…' : 'Save changes'}</Button><Button type="button" onClick={onCancel}>Cancel</Button></div></form><form className="a-panel profile-password-card" onSubmit={savePassword}><ErrorMessage error={passwordError} /><h2>Change password</h2><p className="a-muted">Leave these blank to keep your current password.</p><div className="profile-password-fields"><Field label="Current password" type="password" autoComplete="current-password" required value={passwords.current_password} onChange={(event) => setPasswords({ ...passwords, current_password: event.target.value })} /><Field label="New password" type="password" autoComplete="new-password" minLength={8} required value={passwords.password} onChange={(event) => setPasswords({ ...passwords, password: event.target.value })} /><small>Use at least 8 characters, including a number.</small><Field label="Confirm new password" type="password" autoComplete="new-password" minLength={8} required value={passwords.password_confirmation} onChange={(event) => setPasswords({ ...passwords, password_confirmation: event.target.value })} /></div><div className="a-actions"><Button primary disabled={savingPassword}>{savingPassword ? 'Updating…' : 'Update password'}</Button><Button type="button" onClick={() => setPasswords({ current_password: '', password: '', password_confirmation: '' })}>Cancel</Button></div></form></div>
}

function IncomingOrderAlert({ onOpenOrders }) {
  const knownIds = useRef(null)
  const [incoming, setIncoming] = useState([])

  useEffect(() => {
    let active = true
    let pending = false
    const check = async () => {
      if (pending || document.hidden) return
      pending = true
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
      } finally { pending = false }
    }
    check()
    const timer = setInterval(check, SERVICE_POLL_INTERVAL)
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
