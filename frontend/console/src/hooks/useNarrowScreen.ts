import { useEffect, useState } from 'react'

/** 与运营后台统一使用 768px 导航断点，离开时清除媒体监听。 */
export function useNarrowScreen(): boolean {
  const [narrow, setNarrow] = useState(() => window.matchMedia('(max-width: 768px)').matches)
  useEffect(() => {
    const media = window.matchMedia('(max-width: 768px)')
    const update = () => setNarrow(media.matches)
    media.addEventListener('change', update)
    update()
    return () => media.removeEventListener('change', update)
  }, [])
  return narrow
}
