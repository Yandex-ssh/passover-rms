import { createContext, useContext, useEffect, useState } from 'react'
import { ApiError, get, post } from './api'
import AdminApp from './admin/AdminApp'
import CashierApp from './cashier/CashierApp'
import CustomerOrder from './customer/CustomerOrder'
import './App.css'

const AuthContext = createContext(null)
const useAuth = () => useContext(AuthContext)
const unwrap = (body) => body?.data ?? body

function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    get('/user').then((body) => setUser(unwrap(body))).catch(() => setUser(null)).finally(() => setLoading(false))
  }, [])

  const login = async (email, password) => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    const body = await post('/login', { email, password })
    const authenticatedUser = unwrap(body)
    setUser(authenticatedUser)
    return authenticatedUser
  }

  const logout = async () => {
    await post('/logout', {})
    setUser(null)
  }

  return <AuthContext.Provider value={{ user, loading, login, logout }}>{children}</AuthContext.Provider>
}

function Login() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const authenticatedUser = await login(email, password)
      navigate(authenticatedUser.role === 'cashier' ? '/cashier' : '/admin')
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Network error. Check the connection and try again.')
    } finally {
      setBusy(false)
    }
  }

  return <div className="login-page"><form className="panel form-panel login-card" onSubmit={submit}><div className="brand">PASS-OVER<br /><span>CAFE RMS</span></div><div className="eyebrow">STAFF ACCESS</div><h1>Sign in</h1>{error && <div className="notice error">{error}</div>}<label>Email<input type="email" autoComplete="username" required value={email} onChange={(event) => setEmail(event.target.value)} /></label><label>Password<span className="password-field"><input type={showPassword ? 'text' : 'password'} autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} /><button type="button" className="password-toggle" aria-pressed={showPassword} aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword((visible) => !visible)}>{showPassword ? 'Hide' : 'Show'}</button></span></label><button disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button></form></div>
}

function navigate(path) {
  window.history.pushState({}, '', path)
  window.dispatchEvent(new PopStateEvent('popstate'))
}

function usePath() {
  const [path, setPath] = useState(window.location.pathname)
  useEffect(() => {
    const updatePath = () => setPath(window.location.pathname)
    addEventListener('popstate', updatePath)
    return () => removeEventListener('popstate', updatePath)
  }, [])
  return path
}

function Loading() {
  return <div className="state">Loading…</div>
}

function StaffRouter() {
  const path = usePath()
  const { user, loading, logout } = useAuth()
  if (loading) return <Loading />
  if (!user) return <Login />

  const staffRole = path.startsWith('/cashier') ? 'cashier' : 'admin'
  if (user.role !== staffRole) return <div className="state error"><strong>Unauthorized</strong><span>Your account cannot access this area.</span></div>
  if (staffRole === 'cashier') return <CashierApp user={user} logout={logout} path={path} />
  return <AdminApp user={user} logout={logout} path={path} />
}

function App() {
  const path = usePath()
  if (path.startsWith('/order/')) return <CustomerOrder token={decodeURIComponent(path.split('/')[2] || '')} />
  return <AuthProvider><StaffRouter /></AuthProvider>
}

export default App
