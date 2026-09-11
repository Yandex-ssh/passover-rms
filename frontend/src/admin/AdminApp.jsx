import { go } from './data'
import { useState } from 'react'
import { CategoriesPage, MenuPage, TablesPage, UsersPage } from './Catalog'
import { InventoryPage, OrdersPage, TransactionsPage } from './Operations'
import { DashboardPage, ReportsPage, SystemPage } from './Overview'
import { Button, ErrorMessage, Icon, IconButton, PageHeading } from './shared'
import './admin.css'
import './dashboard-shell.css'

const navigation = [
  ['', 'Dashboard', 'dashboard'], ['users', 'Users', 'users'], ['tables', 'Tables & QR', 'tables'],
  ['categories', 'Categories', 'categories'], ['menu', 'Menu', 'menu'], ['inventory', 'Inventory', 'inventory'],
  ['orders', 'Orders', 'orders'], ['transactions', 'Transactions', 'transactions'], ['reports', 'Reports', 'reports'], ['system', 'System', 'system'],
]

export default function AdminApp({ user, logout, path }) {
  const [open, setOpen] = useState(false)
  const [error, setError] = useState(null)
  const [signingOut, setSigningOut] = useState(false)
  const section = path.replace(/^\/admin\/?/, '').replace(/\/$/, '')
  const pages = { '': DashboardPage, users: UsersPage, tables: TablesPage, categories: CategoriesPage, menu: MenuPage, inventory: InventoryPage, orders: OrdersPage, transactions: TransactionsPage, reports: ReportsPage, system: SystemPage }
  const Page = pages[section]
  const today = new Date().toISOString().slice(0, 10)
  const navigate = (target) => { go(target); setOpen(false) }

  return <div className="admin-app">
    {open && <button className="a-nav-scrim" onClick={() => setOpen(false)} aria-label="Close navigation" />}
    <header className="a-topbar">
      <a className="a-brand" href="/admin" onClick={(event) => { event.preventDefault(); navigate('/admin') }}><Icon name="coffee" size={29} /><span>PASS-OVER CAFE<small>ADMIN WORKSPACE</small></span></a>
      <IconButton className="a-menu-toggle" icon="bars" label="Toggle navigation" aria-expanded={open} onClick={() => setOpen(!open)} />
      <div className="a-topbar-actions"><IconButton className="a-header-icon" icon="bell" label="Notifications" /><button className="a-date" type="button"><Icon name="calendar" size={16} />{today} · UTC<Icon name="chevron" size={14} /></button></div>
    </header>
    <aside className={`a-sidebar ${open ? 'is-open' : ''}`}>
      <nav aria-label="Admin navigation">{navigation.map(([key, label, icon]) => <a key={key} href={`/admin${key ? `/${key}` : ''}`} aria-current={section === key ? 'page' : undefined} onClick={(event) => { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); navigate(`/admin${key ? `/${key}` : ''}`) }}><Icon name={icon} /><span>{label}</span></a>)}</nav>
      <div className="a-sidebar-footer"><div className="a-profile"><div className="a-avatar">{user.name?.[0]?.toUpperCase()}</div><div><strong>{user.name}</strong><small>Administrator</small></div><Icon name="chevron" size={15} /></div><Button icon="logout" disabled={signingOut} onClick={async () => { setSigningOut(true); setError(null); try { await logout() } catch (requestError) { setError(requestError) } finally { setSigningOut(false) } }}>{signingOut ? 'Signing out…' : 'Sign out'}</Button></div>
    </aside>
    <div className="a-workspace"><main className="a-main"><ErrorMessage error={error} />{Page ? <Page key={section} currentUser={user} /> : <><PageHeading eyebrow="404" title="Page not found" /><Button onClick={() => navigate('/admin')}>Back to dashboard</Button></>}</main></div>
  </div>
}
