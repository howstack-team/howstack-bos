/* eslint-disable react/only-export-components -- application entry renders the root directly */
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from './utils/tablePagination';
import React, { useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { createStore } from 'redux';
import { Provider } from 'react-redux';
import { BrowserRouter, Redirect, useLocation } from 'react-router-dom';
import { ConfigProvider, Spin } from '@arco-design/web-react';
import zhCN from '@arco-design/web-react/es/locale/zh-CN';
import enUS from '@arco-design/web-react/es/locale/en-US';
import './style/arco-theme.less';
import './style/global.less';
import './style.css';
import rootReducer from './store';
import PageLayout from './layout';
import Login from './pages/login';
import { GlobalContext } from './context';
import { AuthProvider } from './auth/AuthProvider';
import { CapabilitiesProvider } from './auth/CapabilitiesProvider';
import { useAdminAuth } from './auth/AuthContext';
import changeTheme from './utils/changeTheme';
import useStorage from './utils/useStorage';
import emptyStateImage from './assets/empty-state.png';
import loadingImage from './assets/loading.svg?url';
import ResponsiveTablePagination from './components/ResponsiveTablePagination';

const store = createStore(rootReducer);
const loadingElement = <img src={loadingImage} className="app-loading-icon" alt="加载中" />;

function adminBasePath(): string {
  const assetBase = import.meta.env.BASE_URL.replace(/\/$/, '') || '/';
  const current = window.location.pathname.replace(/\/$/, '') || '/';
  if (current === assetBase || current.startsWith(`${assetBase}/`)) return assetBase;
  const first = current.split('/').filter(Boolean)[0];
  return first ? `/${first}` : '/';
}

function AuthGate() {
  const { status, user } = useAdminAuth();
  const location = useLocation<{ from?: string }>();
  if (status === 'loading') return <div className="app-loading"><Spin size={32} /></div>;
  if (status === 'anonymous' || !user) {
    return location.pathname === '/login'
      ? <Login />
      : <Redirect to={{ pathname: '/login', state: { from: `${location.pathname}${location.search}${location.hash}` } }} />;
  }
  if (location.pathname === '/login') {
    const from = location.state?.from;
    return <Redirect to={from?.startsWith('/') && !from.startsWith('//') && from !== '/login' ? from : '/'} />;
  }
  return <PageLayout />;
}

function Index() {
  const [lang, setLang] = useStorage('arco-lang', 'zh-CN');
  const [theme, setTheme] = useStorage('arco-theme', 'light');

  useEffect(() => { changeTheme(theme); }, [theme]);
  useEffect(() => {
    const reset = () => store.dispatch({ type: 'update-userInfo', payload: {} });
    window.addEventListener('admin:session-cleared', reset);
    return () => window.removeEventListener('admin:session-cleared', reset);
  }, []);

  return <BrowserRouter basename={adminBasePath()}>
    <ConfigProvider locale={lang === 'en-US' ? enUS : zhCN} loadingElement={loadingElement} componentConfig={{
      Card: { bordered: false }, List: { bordered: false },
      Table: { border: false, pagination: { defaultPageSize: DEFAULT_TABLE_PAGE_SIZE, sizeOptions: TABLE_PAGE_SIZE_OPTIONS }, renderPagination: (paginationNode) => <ResponsiveTablePagination paginationNode={paginationNode} /> },
      Empty: { imgSrc: emptyStateImage },
      Spin: { element: loadingElement },
      Switch: { type: 'round' },
      Modal: { maskClosable: true },
      Drawer: { maskClosable: true },
    }}>
      <Provider store={store}>
        <GlobalContext.Provider value={{ lang, setLang, theme, setTheme }}>
          <AuthProvider><CapabilitiesProvider><AuthGate /></CapabilitiesProvider></AuthProvider>
        </GlobalContext.Provider>
      </Provider>
    </ConfigProvider>
  </BrowserRouter>;
}

const root = createRoot(document.getElementById('root')!);
root.render(<Index />);
if (import.meta.hot) import.meta.hot.dispose(() => root.unmount());
