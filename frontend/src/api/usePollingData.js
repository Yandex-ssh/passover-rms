import { useCallback, useEffect, useState } from 'react'
import { get } from './client'

export const SERVICE_POLL_INTERVAL = 2000

export function usePollingData(path) {
  const [revision, setRevision] = useState(0)
  const [state, setState] = useState({ data: null, error: null, loading: true })
  const refresh = useCallback(() => setRevision((value) => value + 1), [])
  useEffect(() => {
    let active = true
    let pending = false
    const check = async () => {
      if (pending || document.hidden) return
      pending = true
      try {
        const result = await get(path)
        if (active) setState({ data: [result], error: null, loading: false })
      } catch (error) {
        if (active) setState((previous) => ({ ...previous, error, loading: false }))
      } finally { pending = false }
    }
    check()
    const timer = setInterval(check, SERVICE_POLL_INTERVAL)
    document.addEventListener('visibilitychange', check)
    return () => { active = false; clearInterval(timer); document.removeEventListener('visibilitychange', check) }
  }, [path, revision])
  return { ...state, refresh }
}
