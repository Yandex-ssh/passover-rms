import { currency, useData } from './data'
import { useState } from 'react'
import { post, put, remove } from '../api'
import { Badge, Button, DataState, Empty, ErrorMessage, Field, Icon, IconButton, Modal, PageHeading, Panel, Table, Tabs } from './shared'

function Editor({ title, initial, fields, endpoint, onClose, onSaved, onDelete }) {
  const [form, setForm] = useState(initial)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const submit = async (event) => {
    event.preventDefault(); setBusy(true); setError(null)
    try { await (initial.id ? put(`${endpoint}/${initial.id}`, form) : post(endpoint, form)); onSaved(); onClose() }
    catch (e) { setError(e) } finally { setBusy(false) }
  }
  return <Modal title={title} onClose={() => !busy && onClose()}><form className="a-form" onSubmit={submit}><ErrorMessage error={error} />{fields.map(({ key, label, options, type, ...props }) => <Field key={key} label={label}>{options ? <select value={String(form[key] ?? '')} onChange={(e) => setForm({ ...form, [key]: e.target.value })} {...props}>{options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select> : type === 'textarea' ? <textarea value={form[key] || ''} onChange={(e) => setForm({ ...form, [key]: e.target.value })} {...props} /> : <input type={type || 'text'} value={form[key] ?? ''} onChange={(e) => setForm({ ...form, [key]: e.target.value })} {...props} />}</Field>)}<div className="a-form-footer">{onDelete && <Button type="button" className="a-danger" disabled={busy} onClick={async () => { if (!confirm(`Delete ${initial.name}?`)) return; setBusy(true); try { await onDelete(); onSaved(); onClose() } catch (e) { setError(e) } finally { setBusy(false) } }}>Delete category</Button>}<Button type="button" onClick={onClose} disabled={busy}>Cancel</Button><Button primary disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</Button></div></form></Modal>
}

export function TablesPage() {
  const resource = useData('/restaurant-tables')
  const [editing, setEditing] = useState(null)
  const [qr, setQr] = useState(null)
  const [qrError, setQrError] = useState(false)
  const tables = [...(resource.data?.[0]?.data || [])].sort((a, b) => a.table_number.localeCompare(b.table_number, undefined, { numeric: true }))
  return <><PageHeading eyebrow="Floor plan" title="Tables & QR"><Button icon="plus" onClick={() => setEditing({ table_number: '', capacity: 2, status: 'available' })}>Add table</Button></PageHeading><DataState resource={resource}><div className="a-card-grid a-tables">{tables.map((table) => <article className="a-table-card" key={table.id}><div className="a-card-heading"><div><span className="a-eyebrow">Table</span><h2>{table.table_number.padStart(2, '0')}</h2></div><Badge value={table.status} /></div><p>Seats {table.capacity}</p><div className="a-actions"><Button icon="tables" onClick={() => { setQr(table); setQrError(false) }}>QR</Button><Button icon="edit" onClick={() => setEditing(table)}>Edit</Button></div></article>)}</div>{!tables.length && <Empty>No tables yet. Add a table to generate its ordering QR code.</Empty>}</DataState>{editing && <Editor title={editing.id ? 'Edit table' : 'Add table'} initial={editing} endpoint="/restaurant-tables" fields={[{ key: 'table_number', label: 'Table number', required: true, maxLength: 50 }, { key: 'capacity', label: 'Seats', type: 'number', required: true, min: 1, step: 1 }, { key: 'status', label: 'Status', options: ['available', 'occupied', 'reserved'].map((s) => [s, s]) }]} onSaved={resource.refresh} onClose={() => setEditing(null)} />}{qr && <Modal title={`Table ${qr.table_number} · Ordering QR`} onClose={() => setQr(null)}><div className="a-qr">{qrError ? <ErrorMessage error={{ message: 'QR code could not be loaded. Close and try again.' }} /> : <img src={`${(import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')}/restaurant-tables/${qr.id}/qr`} alt={`Scan to order at table ${qr.table_number}`} onError={() => setQrError(true)} />}<p>Scan this code to open the menu for this table.</p></div></Modal>}</>
}

export function CategoriesPage() {
  const resource = useData('/categories', '/menu-items')
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const categories = resource.data?.[0]?.data || []
  const items = resource.data?.[1]?.data || []
  const toggle = async (category) => { setBusy(true); setError(null); try { await put(`/categories/${category.id}`, { is_active: !category.is_active }); resource.refresh() } catch (e) { setError(e) } finally { setBusy(false) } }
  return <><PageHeading eyebrow="Menu structure" title="Categories"><Button icon="plus" onClick={() => setEditing({ name: '', description: '' })}>Add category</Button></PageHeading><ErrorMessage error={error} /><DataState resource={resource}><Panel className="a-table-panel"><Table headings={['Category', 'Items', 'Status', 'Actions']} rows={categories.map((category) => <tr key={category.id}><td><strong>{category.name}</strong><small>{category.description}</small></td><td>{items.filter((item) => item.category_id === category.id).length} items</td><td><Badge value={category.is_active ? 'active' : 'inactive'} /></td><td><div className="a-actions"><IconButton icon="edit" label={`Edit ${category.name}`} onClick={() => setEditing(category)} /><IconButton icon="power" label={`${category.is_active ? 'Deactivate' : 'Activate'} ${category.name}`} disabled={busy} onClick={() => toggle(category)} /></div></td></tr>)} /></Panel></DataState>{editing && <Editor title={editing.id ? 'Edit category' : 'Add category'} initial={editing} fields={[{ key: 'name', label: 'Category name', required: true, maxLength: 100 }, { key: 'description', label: 'Description', type: 'textarea' }]} endpoint="/categories" onDelete={editing.id ? () => remove(`/categories/${editing.id}`) : null} onSaved={resource.refresh} onClose={() => setEditing(null)} />}</>
}

export function MenuPage() {
  const resource = useData('/categories', '/menu-items', '/inventories')
  const [category, setCategory] = useState('all')
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const categories = resource.data?.[0]?.data || []
  const items = resource.data?.[1]?.data || []
  const inventories = resource.data?.[2]?.data || []
  const toggle = async (item) => { setBusy(true); setError(null); try { await put(`/menu-items/${item.id}`, { is_available: !item.is_available }); resource.refresh() } catch (e) { setError(e) } finally { setBusy(false) } }
  const visible = items.filter((item) => category === 'all' || String(item.category_id) === category)
  return <><PageHeading eyebrow="Kitchen catalog" title="Menu items"><Button icon="plus" onClick={() => setEditing({ name: '', description: '', price: '', category_id: '' })}>Add menu item</Button></PageHeading><ErrorMessage error={error} /><DataState resource={resource}><Tabs values={[["all", 'All'], ...categories.map((c) => [String(c.id), c.name])]} value={category} onChange={setCategory} /><div className="a-card-grid a-menu-grid">{visible.map((item) => { const inventory = inventories.find((i) => i.menu_item.id === item.id); return <article className="a-menu-card" key={item.id}><div className="a-item-icon"><Icon name="menu" size={30} /></div><div className="a-card-heading"><h3>{item.name}</h3><strong>{currency(item.price)}</strong></div><small>{item.category?.name}</small><p>{item.description || 'No description added.'}</p><div className="a-badges"><Badge value={item.is_available ? 'available' : 'unavailable'} />{inventory && <Badge value={inventory.quantity === 0 ? 'out-of-stock' : inventory.is_low_stock ? 'low-stock' : 'in-stock'}>{inventory.quantity === 0 ? 'Out of stock' : inventory.is_low_stock ? 'Low stock' : `${inventory.quantity} in stock`}</Badge>}</div><div className="a-actions"><Button icon="edit" onClick={() => setEditing({ id: item.id, name: item.name, category_id: item.category_id, description: item.description || '', price: item.price })}>Edit</Button><Button icon="power" disabled={busy} onClick={() => toggle(item)}>Mark {item.is_available ? 'unavailable' : 'available'}</Button></div></article> })}</div>{!visible.length && <Empty>No menu items in this category.</Empty>}</DataState>{editing && <Editor title={editing.id ? 'Edit menu item' : 'Add menu item'} initial={editing} endpoint="/menu-items" fields={[{ key: 'name', label: 'Name', required: true, maxLength: 150 }, { key: 'category_id', label: 'Category', required: true, options: [['', 'Choose category'], ...categories.map((c) => [c.id, c.name])] }, { key: 'price', label: 'Price (₱)', type: 'number', required: true, min: 0, step: '0.01' }, { key: 'description', label: 'Description', type: 'textarea' }]} onSaved={resource.refresh} onClose={() => setEditing(null)} />}</>
}

export function UsersPage({ currentUser }) {
  const resource = useData('/admin/users')
  const [editing, setEditing] = useState(null)
  const [passwordUser, setPasswordUser] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const mutate = async (fn) => { setBusy(true); setError(null); try { await fn(); resource.refresh() } catch (e) { setError(e) } finally { setBusy(false) } }
  const users = resource.data?.[0]?.data || []
  return <><PageHeading eyebrow="Staff accounts" title="Users"><Button icon="plus" onClick={() => setEditing({ name: '', email: '', role: 'cashier', password: '', password_confirmation: '' })}>Create account</Button></PageHeading><ErrorMessage error={error} /><DataState resource={resource}><Panel className="a-table-panel"><Table headings={['Name', 'Email', 'Role', 'Status', 'Last active', 'Actions']} rows={users.map((user) => <tr key={user.id}><td><strong>{user.name}</strong>{currentUser.id === user.id && <small>You</small>}</td><td>{user.email}</td><td><select aria-label={`Role for ${user.name}`} value={user.role} disabled={busy} onChange={(e) => mutate(() => put(`/admin/users/${user.id}`, { role: e.target.value }))}><option value="admin">Admin</option><option value="cashier">Cashier</option></select></td><td><Badge value={user.is_active ? 'active' : 'inactive'} /></td><td><span className="a-muted" title="Login activity is not recorded">Not recorded</span></td><td><div className="a-actions"><IconButton icon="edit" label={`Edit ${user.name}`} onClick={() => setEditing({ id: user.id, name: user.name, email: user.email, role: user.role })} /><IconButton icon="power" label={`${user.is_active ? 'Deactivate' : 'Activate'} ${user.name}`} disabled={busy} onClick={() => mutate(() => post(`/admin/users/${user.id}/${user.is_active ? 'deactivate' : 'activate'}`, {}))} /><Button onClick={() => setPasswordUser(user)}>Password</Button><Button className="a-danger" disabled={busy || currentUser.id === user.id} onClick={() => { if (confirm(`Delete ${user.name}? This account will no longer be able to sign in.`)) mutate(() => remove(`/admin/users/${user.id}`)) }}>Delete</Button></div></td></tr>)} /></Panel></DataState>{editing && <Editor title={editing.id ? 'Edit account' : 'Create account'} initial={editing} endpoint="/admin/users" onSaved={resource.refresh} onClose={() => setEditing(null)} fields={[{ key: 'name', label: 'Name', required: true }, { key: 'email', label: 'Email', type: 'email', required: true }, { key: 'role', label: 'Role', options: [['cashier', 'Cashier'], ['admin', 'Admin']] }, ...(!editing.id ? [{ key: 'password', label: 'Password', type: 'password', minLength: 8, required: true, autoComplete: 'new-password' }, { key: 'password_confirmation', label: 'Confirm password', type: 'password', minLength: 8, required: true, autoComplete: 'new-password' }] : [])]} />}{passwordUser && <PasswordDialog user={passwordUser} onClose={() => setPasswordUser(null)} />}</>
}

function PasswordDialog({ user, onClose }) {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmation, setShowConfirmation] = useState(false)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  return <Modal title={`Set password · ${user.name}`} onClose={() => !busy && onClose()}><form className="a-form" onSubmit={async (e) => { e.preventDefault(); setBusy(true); setError(null); try { await post(`/admin/users/${user.id}/password`, { password, password_confirmation: confirmation }); onClose() } catch (e) { setError(e) } finally { setBusy(false) } }}><ErrorMessage error={error} /><Field label="New password"><span className="password-field"><input type={showPassword ? 'text' : 'password'} autoComplete="new-password" minLength={8} required value={password} onChange={(e) => setPassword(e.target.value)} /><button type="button" className="password-toggle" aria-pressed={showPassword} aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword((visible) => !visible)}>{showPassword ? 'Hide' : 'Show'}</button></span></Field><Field label="Confirm password"><span className="password-field"><input type={showConfirmation ? 'text' : 'password'} autoComplete="new-password" minLength={8} required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} /><button type="button" className="password-toggle" aria-pressed={showConfirmation} aria-label={showConfirmation ? 'Hide confirmed password' : 'Show confirmed password'} onClick={() => setShowConfirmation((visible) => !visible)}>{showConfirmation ? 'Hide' : 'Show'}</button></span></Field><div className="a-form-footer"><Button type="button" disabled={busy} onClick={onClose}>Cancel</Button><Button primary disabled={busy}>{busy ? 'Saving…' : 'Set password'}</Button></div></form></Modal>
}
