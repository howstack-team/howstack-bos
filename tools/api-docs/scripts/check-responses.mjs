import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { responseValidator } from './responses.mjs';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const origin = process.env.API_DOCS_TEST_ORIGIN ?? 'http://127.0.0.1:8989';
const sessionPath = process.env.API_DOCS_TEST_SESSION;
if (!sessionPath) throw new Error('请设置 API_DOCS_TEST_SESSION，指向已授权测试管理员的 Cookie JSON 文件；脚本不会登录或创建账号。');
const session = JSON.parse(readFileSync(sessionPath, 'utf8'));
if (!/^[\w-]+$/.test(session.name) || !session.value || /[;\r\n]/.test(session.value)) throw new Error('测试会话文件格式不正确');
const headers = { Cookie: `${session.name}=${session.value}` };
// 只执行固定、无副作用的读取，禁止扫描后自动调用写接口。
const checks = [
  ['admin', '/api/admin/v1/auth/me'], ['admin', '/api/admin/v1/permissions'],
  ['admin', '/api/admin/v1/members'], ['admin', '/api/admin/v1/orders'],
  ['console', '/api/console/v1/auth/registration'],
];
for (const [group, path] of checks) {
  const document = JSON.parse(readFileSync(resolve(repo, `backend/resources/api-docs/specs/${group}.json`), 'utf8'));
  const response = await fetch(new URL(path, origin), { headers, signal: AbortSignal.timeout(10000) });
  assert.equal(response.status, 200, `${path} 必须使用有权限的测试账号，不能把错误响应算作成功验收`);
  const value = await response.json();
  const validate = responseValidator(document, path, 'get', 200);
  assert.ok(validate(value), `${path} 与契约不符：${JSON.stringify(validate.errors)}`);
  assert.ok(!validate({ ...value, code: '错误类型' }), `${path} 的契约必须能够拒绝错误信封`);
  assert.equal(response.headers.get('x-request-id'), value.request_id);
  console.log(`实际响应符合契约：GET ${path}`);
}
console.log('只读接口响应检查通过；不代表全部写接口或第三方业务链路已验收。');
