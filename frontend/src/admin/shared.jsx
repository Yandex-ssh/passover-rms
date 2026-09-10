import { useEffect, useRef } from 'react'
import { titleCase } from './data'

const paths = {
  dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
  users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
  tables: 'M3 3h6v6H3z M15 3h6v6h-6z M3 15h6v6H3z M15 15h2v2h-2z M21 14v3 M14 21h3 M20 20h1v1h-1z',
  categories: 'M20 13l-7 7a2 2 0 0 1-3 0l-8-8V3h9l9 8a2 2 0 0 1 0 2z M7 7h.01',
  menu: 'M4 3v5a3 3 0 0 0 6 0V3 M7 3v18 M20 21V3c-5 3-5 10 0 10',
  inventory: 'M12 3l9 5v9l-9 5-9-5V8z M3 8l9 5 9-5 M12 13v9 M7 5.8l9 5',
  orders: 'M9 4H5v17h14V4h-4 M9 2h6v4H9z M8 11h8 M8 16h6',
  transactions: 'M5 3h14v18l-3-2-4 2-4-2-3 2z M15 8h-4a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4H9 M12 6v12',
  reports: 'M4 3v18h17 M8 16v-5 M13 16V7 M18 16V4',
  system: 'M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
  edit: 'M16 3l5 5-12 12-6 1 1-6z M13 6l5 5',
  power: 'M12 2v10 M6 5a9 9 0 1 0 12 0',
  plus: 'M12 5v14 M5 12h14',
  close: 'M6 6l12 12 M6 18L18 6',
  history: 'M3 11a9 9 0 1 1 2 7 M3 4v7h7 M12 7v5l3 2',
  logout: 'M9 3H3v18h6 M9 12h12 M16 7l5 5-5 5',
  chevron: 'M9 5l7 7-7 7',
  alert: 'M12 3L2 21h20z M12 9v5 M12 18h.01',
  check: 'M4 12l5 5L20 6',
  search: 'M16 16l5 5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
  coffee: 'M4 8h13v7a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z M17 9h2a3 3 0 0 1 0 6h-2 M7 2v3 M12 2v3 M2 23h18',
  bars: 'M3 6h18 M3 12h18 M3 18h18',
  download: 'M12 3v12 M7 10l5 5 5-5 M4 16v5h16v-5',
}
export function Icon({ name, size = 19 }) { return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name] || paths.dashboard} /></svg> }
export function Button({ children, icon, primary, className = '', ...props }) { return <button className={`a-button ${primary ? 'primary' : ''} ${className}`} {...props}>{icon && <Icon name={icon} size={16} />}{children}</button> }
export function IconButton({ icon, label, ...props }) { return <Button icon={icon} aria-label={label} title={label} {...props} /> }
export function Badge({ value, children }) { return <span className={`a-badge ${String(value || '').replaceAll(' ', '-')}`}>{children || titleCase(value)}</span> }
export function PageHeading({ eyebrow, title, children, description }) { return <div className="a-page-heading"><div><div className="a-eyebrow">{eyebrow}</div><h1>{title}</h1>{description && <p>{description}</p>}</div>{children}</div> }
export function Panel({ title, action, children, className = '' }) { return <section className={`a-panel ${className}`}>{title && <div className="a-panel-heading"><h2>{title}</h2>{action}</div>}{children}</section> }
export function Empty({ children = 'No records yet.' }) { return <div className="a-empty">{children}</div> }
export function ErrorMessage({ error }) { return error ? <div className="a-error" role="alert">{error.message || 'Could not connect. Please try again.'}</div> : null }
export function DataState({ resource, children }) { return resource.loading ? <div className="a-empty" role="status">Loading…</div> : resource.error ? <div><ErrorMessage error={resource.error} /><Button onClick={resource.refresh}>Try again</Button></div> : children }
export function Table({ headings, rows, empty = 'No records found.' }) { return <div className="a-table-wrap"><table><thead><tr>{headings.map((h) => <th key={h}>{h}</th>)}</tr></thead><tbody>{rows}</tbody></table>{(!rows || rows.length === 0) && <Empty>{empty}</Empty>}</div> }
export function Pagination({ meta, onPage }) { if (!meta?.total) return null; return <div className="a-pagination"><span>{meta.from}–{meta.to} of {meta.total}</span><div className="a-actions"><Button disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Previous</Button><span>Page {meta.current_page} of {meta.last_page}</span><Button disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</Button></div></div> }
export function Modal({ title, children, onClose }) {
  const ref = useRef(null)
  useEffect(() => { const dialog = ref.current; const previous = document.activeElement; dialog.showModal(); return () => { dialog.close(); previous?.focus() } }, [])
  return <dialog ref={ref} className="a-modal" aria-label={title} onCancel={(e) => { e.preventDefault(); onClose() }} onClick={(e) => { if (e.target === ref.current) onClose() }}><div className="a-modal-heading"><h2>{title}</h2><IconButton icon="close" label="Close dialog" onClick={onClose} /></div>{children}</dialog>
}
export function Field({ label, children, ...props }) { return <label className="a-field"><span>{label}</span>{children || <input {...props} />}</label> }
export function Tabs({ values, value, onChange }) { return <div className="a-tabs" aria-label="Filter">{values.map(([key, label]) => <Button key={key} aria-pressed={value === key} primary={value === key} onClick={() => onChange(key)}>{label}</Button>)}</div> }
export function Metric({ icon, label, value, note, onClick }) { return <div className="a-metric"><div className="a-metric-top"><Icon name={icon} size={26} /><span>{label}</span></div><strong>{value}</strong>{onClick ? <button className="a-text-button" onClick={onClick}>{note}<Icon name="chevron" size={14} /></button> : <small>{note}</small>}</div> }
