import React from 'react'
import { createRoot } from 'react-dom/client'
import '@arco-design/web-react/es/_util/react-19-adapter'
import '@arco-design/web-react/dist/css/arco.css'
import InstallerApp from './App'
import './styles.css'

const root = document.getElementById('root')
if (!root) throw new Error('安装器根节点不存在')

createRoot(root).render(
  <React.StrictMode>
    <InstallerApp />
  </React.StrictMode>,
)
