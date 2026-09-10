import { go } from './data'
import { useState } from 'react'
import { CategoriesPage, MenuPage, TablesPage, UsersPage } from './Catalog'
import { InventoryPage, OrdersPage, TransactionsPage } from './Operations'
import { DashboardPage, ReportsPage, SystemPage } from './Overview'
import { Button, ErrorMessage, Icon, IconButton, PageHeading } from './shared'
import './admin.css'

const navigation = [
  ['', 'Dashboard', 'dashboard'],
  ['users', 'Users', 'users'],
  ['tables', 'Tables & QR', 'tables'],
  ['categories', 'Categories', 'categories'],
  ['menu', 'Menu', 'menu'],
  ['inventory', 'Inventory', 'inventory'],
  ['orders', 'Orders', 'orders'],
  ['transactions', 'Transactions', 'transactions'],
  ['reports', 'Reports', 'reports'],
  ['system', 'System', 'system'],
]

export default function AdminApp({ user, logout, path }) {
  const [open, setOpen] = useState(false)
  const [error, setError] = useState(null)
  const [signingOut, setSigningOut] = useState(false)
  const section = path.replace(/^\/admin\/?/, '').replace(/\/$/, '')
  const entry = navigation.find(([key]) => key === section)
  const pages = { '': DashboardPage, users: UsersPage, tables: TablesPage, categories: CategoriesPage, menu: MenuPage, inventory: InventoryPage, orders: OrdersPage, transactions: TransactionsPage, reports: ReportsPage, system: SystemPage }
  const Page = pages[section]
  return <div className="admin-app">{open && <button className="a-nav-scrim" onClick={() => setOpen(false)} aria-label="Close navigation" />}<aside className={`a-sidebar ${open ? 'is-open' : ''}`}><a className="a-brand" href="/admin" onClick={(e) => { e.preventDefault(); go('/admin'); setOpen(false) }}><Icon name="coffee" size={29} /><span>PASS-OVER CAFE<small>ADMIN WORKSPACE</small></span></a><nav aria-label="Admin navigation">{navigation.map(([key, label, icon]) => <a key={key} href={`/admin${key ? `/${key}` : ''}`} aria-current={section === key ? 'page' : undefined} onClick={(e) => { if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; e.preventDefault(); go(`/admin${key ? `/${key}` : ''}`); setOpen(false) }}><Icon name={icon} /><span>{label}</span></a>)}</nav><div className="a-sidebar-footer"><div className="a-profile"><div className="a-avatar">{user.name?.[0]?.toUpperCase()}</div><div><strong>{user.name}</strong><small>Administrator</small></div></div><Button icon="logout" disabled={signingOut} onClick={async () => { setSigningOut(true); setError(null); try { await logout() } catch (e) { setError(e) } finally { setSigningOut(false) } }}>{signingOut ? 'Signing out…' : 'Sign out'}</Button></div></aside><div className="a-workspace"><header className="a-topbar"><div><IconButton className="a-menu-toggle" icon="bars" label="Toggle navigation" aria-expanded={open} onClick={() => setOpen(!open)} /><span>Admin</span><span className="a-breadcrumb-slash">/</span><strong>{entry?.[1] || 'Page not found'}</strong></div><span className="a-topbar-note"><span /> Staff workspace</span></header><main className="a-main"><ErrorMessage error={error} />{Page ? <Page key={section} currentUser={user} /> : <><PageHeading eyebrow="404" title="Page not found" /><Button onClick={() => go('/admin')}>Back to dashboard</Button></>}</main></div></div>
}
