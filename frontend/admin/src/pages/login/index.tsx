import React, { useEffect } from 'react';
import { Card } from '@arco-design/web-react';
import LoginForm from './form';
import styles from './style/index.module.less';

/** 居中展示管理员登录入口，并在离开时恢复原页面标题。 */
function Login() {
  useEffect(() => {
    const previousTitle = document.title;
    document.title = '云业务运营后台';
    document.body.setAttribute('arco-theme', 'light');
    return () => { document.title = previousTitle; };
  }, []);

  return (
    <div className={styles.container}>
      <main className={styles.content} aria-labelledby="login-title">
        <Card bordered bodyStyle={{ padding: 0 }}>
          <div className={styles.panel}>
            <header className={styles.heading}>
              <h1 id="login-title" className={styles.title}>云业务运营后台</h1>
              <p className={styles.subtitle}>请使用管理员账号登录</p>
            </header>
            <LoginForm />
          </div>
        </Card>
      </main>
    </div>
  );
}
Login.displayName = 'LoginPage';

export default Login;
