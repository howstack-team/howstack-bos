import { useHistory, useLocation } from 'react-router-dom'

/** 在现有业务页面保存编辑入口，支持刷新、浏览器返回及保留工作台 Tab；queryKey 区分编辑与资源对应等任务。 */
export default function useEditorLocation(queryKey = 'edit') {
  const history = useHistory()
  const location = useLocation()
  const value = new URLSearchParams(location.search).get(queryKey)
  const editor: 'new' | number | null = value === 'new' ? 'new' : value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)) ? Number(value) : null
  const openEditor = (id?: number) => {
    const params = new URLSearchParams(location.search)
    params.set(queryKey, id === undefined ? 'new' : String(id))
    history.push({ ...location, search: params.toString() })
  }
  const closeEditor = () => {
    const params = new URLSearchParams(location.search)
    params.delete(queryKey)
    history.replace({ ...location, search: params.toString() })
  }
  return { editor, openEditor, closeEditor }
}
