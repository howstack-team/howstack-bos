import assert from 'node:assert/strict';
import test from 'node:test';
import { responseGaps, responseValidator } from '../scripts/responses.mjs';

const document = { paths: { '/api/example': { get: { responses: {
  200: { $ref: '#/components/responses/Ok' }, 204: { description: '无内容' }, 400: { description: '错误' },
} } } }, components: { responses: { Ok: { description: '成功', content: { 'application/json': { schema: { $ref: '#/components/schemas/Result' } } } } },
  schemas: { Result: { type: 'object', required: ['code', 'data'], properties: { code: { const: 0 }, data: { type: 'integer' } } } } } };

test('真实引用根的响应校验能拒绝错误类型与缺失字段', () => {
  const validate = responseValidator(document, '/api/example', 'get', 200);
  assert.ok(validate({ code: 0, data: 1 }));
  assert.ok(!validate({ code: 0, data: '1' }));
  assert.ok(!validate({ code: 0 }));
  assert.ok(!validate({ code: 1, data: 1 }));
});

test('不误报无正文错误与 204，引用内的 partial 必须报告', () => {
  assert.deepEqual(responseGaps({ admin: document }), []);
  const partial = structuredClone(document);
  partial.components.schemas.Result.properties.data = { 'x-schema-status': 'partial' };
  assert.equal(responseGaps({ admin: partial }).length, 1);
  assert.equal(responseGaps({ admin: partial })[0].status, '200');
});
