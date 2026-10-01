import React, { useRef, useState } from 'react';
import { Form, Input, Checkbox, Link, Button, Space } from '@arco-design/web-react';
import type { FormInstance } from '@arco-design/web-react/es/Form';
import { IconLock, IconUser } from '@arco-design/web-react/icon';
import { useAdminAuth } from '@/auth/AuthContext';
import { passwordHelpUrl } from '@/auth/helpLinks';
import useLocale from '@/utils/useLocale';
import locale from './locale';
import styles from './style/index.module.less';

export default function LoginForm() {
  const formRef = useRef<FormInstance>();
  const { login, bootstrapError } = useAdminAuth();
  const [errorMessage, setErrorMessage] = useState(bootstrapError);
  const [loading, setLoading] = useState(false);
  const [remember, setRemember] = useState(false);
  const t = useLocale(locale);

  async function submit() {
    try {
      const values = await formRef.current.validate();
      setErrorMessage('');
      setLoading(true);
      await login({ username: values.username, password: values.password, remember });
    } catch (error) {
      if (error instanceof Error) setErrorMessage(error.message);
    } finally {
      setLoading(false);
    }
  }

  return <div className={styles['login-form-wrapper']}>
    <div className={styles['login-form-title']}>登录运营后台</div>
    <div className={styles['login-form-sub-title']}>皓量云擎云业务运营系统</div>
    <div className={styles['login-form-error-msg']}>{errorMessage}</div>
    <Form className={styles['login-form']} layout="vertical" ref={formRef}>
      <Form.Item field="username" rules={[{ required: true, message: t['login.form.userName.errMsg'] }]}>
        <Input prefix={<IconUser />} placeholder="管理员账号" autoComplete="username" onPressEnter={() => void submit()} />
      </Form.Item>
      <Form.Item field="password" rules={[{ required: true, message: t['login.form.password.errMsg'] }]}>
        <Input.Password prefix={<IconLock />} placeholder="管理员密码" autoComplete="current-password" onPressEnter={() => void submit()} />
      </Form.Item>
      <Space size={16} direction="vertical">
        <div className={styles['login-form-password-actions']}>
          <Checkbox checked={remember} onChange={setRemember}>{t['login.form.rememberPassword']}</Checkbox>
          <Link href={passwordHelpUrl || undefined} target={passwordHelpUrl ? '_blank' : undefined}
            onClick={passwordHelpUrl ? undefined : (event) => event.preventDefault()}>
            {t['login.form.forgetPassword']}
          </Link>
        </div>
        <Button type="primary" long onClick={() => void submit()} loading={loading}>{t['login.form.login']}</Button>
      </Space>
    </Form>
  </div>;
}
