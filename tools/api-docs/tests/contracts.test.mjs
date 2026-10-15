import assert from 'node:assert/strict';
import test from 'node:test';
import { assemble, normalizePath, matchesRoute, transform, expandPathRefs } from '../scripts/contracts.mjs';

const source = (file, paths, components = {}) => ({ file, namespace: file.replace(/\W/g, '_'), document: { openapi: '3.0.3',
  info: { title: '测试', version: '1.0.0', description: '字段补充' },
  servers: [{ url: '/api/admin/v1' }], paths, components } });
const operation = { summary: '测试接口', responses: { 200: { description: '成功' } } };
const route = { method: 'get', path: '/api/admin/v1/example/{id:\\d+}', middleware: [] };

test('规范化嵌套正则量词并保留参数名；不同方法和路由不误算覆盖', () => {
  assert.equal(normalizePath('/x/{code:[a-z][a-z0-9_]{0,63}}/{id:\\d+}'), '/x/{code}/{id}');
  assert.ok(matchesRoute('/x/help/categories/{id}', '/x/{kind:help|news}/{resource:categories|entries}/{id:\\d+}'));
  assert.ok(!matchesRoute('/x/other/categories/{id}', '/x/{kind:help|news}/{resource:categories|entries}/{id:\\d+}'));
  assert.ok(!matchesRoute('/x/{another}', '/x/{id:\\d+}'));
  const result = assemble([source('one.v1.yaml', { '/example/{id}': { get: operation } })], [route, { ...route, method: 'post' }]);
  assert.equal(result.coverage.covered, 1);
  assert.equal(result.coverage.missing[0].method, 'post');
});

test('组件隔离；重复操作拒绝合并，查询补充只能增加参数而不能替换响应', () => {
  const main = source('main.v1.yaml', { '/example/{id}': { get: { ...operation, responses: { 200: { $ref: '#/components/responses/Ok' } } } } },
    { responses: { Ok: { description: '具体业务响应' } } });
  const filter = source('admin-list-filters.v1.yaml', { '/example/{id}': { get: { ...operation, parameters: [{ name: 'status', in: 'query', schema: { type: 'integer' } }] } } });
  const merged = assemble([filter, main], [route]);
  const documented = merged.groups.admin.paths['/api/admin/v1/example/{id}'].get;
  assert.equal(documented.responses[200].$ref, '#/components/responses/main_v1_yaml_Ok');
  assert.ok(documented.parameters.some(param => param.name === 'status'));
  assert.throws(() => assemble([main, main], [route]), /重复接口/);
});

test('3.0 可空 enum 和引用转换不会丢失原约束', () => {
  assert.deepEqual(transform({ type: 'string', enum: ['a'], nullable: true }, 'n', '3.0.3'), { type: ['string', 'null'], enum: ['a', null] });
  assert.deepEqual(transform({ $ref: '#/components/schemas/X', nullable: true }, 'n', '3.0.3'), { anyOf: [{ $ref: '#/components/schemas/n_X' }, { type: 'null' }] });
});

test('path 内的本地引用展开，组件引用保持可复用；循环引用拒绝', () => {
  const document = { paths: { '/x': { post: { requestBody: { required: true } }, put: { requestBody: { $ref: '#/paths/~1x/post/requestBody' } } } } };
  assert.deepEqual(expandPathRefs(document, document).paths['/x'].put.requestBody, { required: true });
  assert.throws(() => expandPathRefs({ $ref: '#/loop' }, { loop: { $ref: '#/loop' } }), /循环/);
});

test('参数引用解析后不重复追加 path 参数；失效契约明确报告', () => {
  const item = source('params.v1.yaml', { '/example/{id}': { get: { ...operation, parameters: [{ $ref: '#/components/parameters/Id' }] } }, '/removed': { get: operation } },
    { parameters: { Id: { name: 'id', in: 'path', required: true, schema: { type: 'integer' } } } });
  const result = assemble([item], [route]);
  assert.equal(result.groups.admin.paths['/api/admin/v1/example/{id}'].get.parameters.length, 1);
  assert.equal(result.coverage.stale[0].path, '/api/admin/v1/removed');
});

test('相同认证合并，Cookie 与不同认证要求保持独立', () => {
  const document = source('auth.v1.yaml', { '/a': { get: { ...operation, security: [{ A: [] }] } },
    '/b': { get: { ...operation, security: [{ B: [], Header: [] }] } } },
  { securitySchemes: { A: { type: 'apiKey', in: 'cookie', name: 'hlyq_admin_token' },
    B: { type: 'apiKey', in: 'cookie', name: 'hlyq_admin_token', description: '模块说明' },
    Header: { type: 'apiKey', in: 'header', name: 'X-Test-Key' } } });
  const { groups } = assemble([document], ['/a', '/b'].map(path => ({ ...route, path: '/api/admin/v1' + path })));
  assert.equal(Object.keys(groups.admin.components.securitySchemes).length, 2);
  const selection = groups.admin.paths['/api/admin/v1/b'].get.security[0];
  assert.deepEqual(Object.keys(selection), ['adminCookie', 'auth_v1_yaml_Header']);
});
