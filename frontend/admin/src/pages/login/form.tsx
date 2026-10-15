import React, { useEffect, useRef, useState } from 'react';
import { Message, Form, Input, Checkbox, Link, Button } from '@arco-design/web-react';
import { IconLock, IconUser } from '@arco-design/web-react/icon';
import type { FormInstance } from '@arco-design/web-react/es/Form';
import { useAdminAuth } from '@/auth/AuthContext';
import type { AdminLoginInput } from '@/auth/authApi';
import { passwordHelpUrl } from '@/auth/helpLinks';
import { ApiRequestError } from '@/api/httpClient';
import useLocale from '@/utils/useLocale';
import locale from './locale';
import styles from './style/index.module.less';

type LoginFields = Pick<AdminLoginInput, 'username' | 'password'> & { code?: string };

/** 管理员账号密码表单，保留会话记忆、错误反馈和提交中状态。 */
export default function LoginForm() {
  const formRef = useRef<FormInstance<LoginFields>>();
  const { login, verifyMfa, bootstrapError } = useAdminAuth();
  const submitting = useRef(false);
  const reportedBootstrapError = useRef('');
  const [loading, setLoading] = useState(false);
  const [challenge, setChallenge] = useState<number | null>(null);
  const [recovery, setRecovery] = useState(false);
  const [remember, setRemember] = useState(false);
  const t = useLocale(locale);

  useEffect(() => {
    if (bootstrapError && bootstrapError !== reportedBootstrapError.current) Message.error(bootstrapError);
    reportedBootstrapError.current = bootstrapError;
  }, [bootstrapError]);

  async function submit() {
    const form = formRef.current;
    if (!form || submitting.current) return;
    submitting.current = true;

    let values: LoginFields;
    try {
      values = await form.validate();
    } catch {
      // Form.Item 已展示字段校验结果，无需再展示组件内部异常。
      submitting.current = false;
      return;
    }

    setLoading(true);
    try {
      if (challenge !== null) {
        await verifyMfa(values.code ?? '', recovery);
      } else {
        const result = await login({ username: values.username, password: values.password, remember });
        if (result.mfa_required) {
          form.resetFields();
          setChallenge(result.expires_at);
          setRecovery(false);
        }
      }
    } catch (error) {
      // 服务端参数错误只反馈到已登记的字段，认证或网络失败使用单次 Message。
      if (error instanceof ApiRequestError && error.status === 422 && error.data && typeof error.data === 'object' && 'errors' in error.data) {
        const fields = error.data.errors;
        if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
          let mapped = false;
          for (const key of ['username', 'password', 'code'] as const) {
            if (!Object.hasOwn(fields, key)) continue;
            const messages: unknown = fields[key as keyof typeof fields];
            if (Array.isArray(messages) && messages.length && messages.every((value: unknown) => typeof value === 'string')) {
              form.setFields({ [key]: { error: { message: messages.join('；') } } });
              mapped = true;
            }
          }
          if (mapped) return;
        }
      }
      if (challenge !== null && error instanceof ApiRequestError && error.status === 401) { setChallenge(null); form.resetFields(); }
      Message.error(error instanceof Error ? error.message : '登录失败，请稍后重试');
    } finally {
      submitting.current = false;
      setLoading(false);
    }
  }

  return <div className={styles['login-form-wrapper']}>
    <Form<LoginFields> layout="vertical" size="large" requiredSymbol={false} ref={formRef}>
      {challenge === null ? <>
      <Form.Item field="username" label="管理员账号" style={{ marginBottom: 20 }} rules={[{ required: true, message: t['login.form.userName.errMsg'] }, { maxLength: 32, message: '管理员账号不能超过32个字符' }, { match: /\S/, message: '请输入管理员账号' }]}>
        <Input className={styles['login-form-input']} prefix={<IconUser />} placeholder="请输入管理员账号" maxLength={32} autoComplete="username" onPressEnter={() => void submit()} />
      </Form.Item>
      <Form.Item field="password" label="密码" style={{ marginBottom: 20 }} rules={[{ required: true, message: t['login.form.password.errMsg'] }, { maxLength: 128, message: '密码不能超过128个字符' }]}>
        <Input.Password visibilityToggle={false} className={styles['login-form-input']} prefix={<IconLock />} placeholder="请输入管理员密码" maxLength={128} autoComplete="current-password" onPressEnter={() => void submit()} />
      </Form.Item>
      </> : <>
        <p>请输入{recovery ? '未使用的恢复码' : '验证器中的6位动态验证码'}，完成管理员身份验证。</p>
        <Form.Item field="code" label={recovery ? '恢复码' : '验证器验证码'} rules={[{ required: true, message: recovery ? '请输入恢复码' : '请输入验证码' }, { match: recovery ? /^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{8}){3})$/ : /^[0-9]{6}$/, message: recovery ? '恢复码格式不正确' : '请输入6位数字验证码' }]}>
          <Input placeholder={recovery ? '请输入一次性恢复码' : '请输入6位验证码'} autoComplete="one-time-code" inputMode={recovery ? 'text' : 'numeric'} maxLength={recovery ? 35 : 6} onPressEnter={() => void submit()} />
        </Form.Item>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 20 }}>
          <Link onClick={() => { setRecovery(!recovery); formRef.current?.resetFields(); }}>{recovery ? '使用验证器' : '使用恢复码'}</Link>
          <Link onClick={() => { setChallenge(null); formRef.current?.resetFields(); }}>返回密码登录</Link>
        </div>
      </>}
      <div className={styles['login-form-actions']}>
        {challenge === null && <div className={styles['login-form-password-actions']}>
          <Checkbox checked={remember} onChange={setRemember}>{t['login.form.rememberPassword']}</Checkbox>
          {passwordHelpUrl && <Link href={passwordHelpUrl} target="_blank" rel="noopener noreferrer">
            {t['login.form.forgetPassword']}
          </Link>}
        </div>}
        <Button className={styles['login-form-submit']} type="primary" size="large" long onClick={() => void submit()} loading={loading}>{challenge === null ? t['login.form.login'] : '验证并登录'}</Button>
      </div>
    </Form>
  </div>;
}
