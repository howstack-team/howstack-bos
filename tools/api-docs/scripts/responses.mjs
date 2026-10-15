import Ajv from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { methods } from './contracts.mjs';

export const pointer = parts => '#/' + parts.map(part => String(part).replace(/~/g, '~0').replace(/\//g, '~1')).join('/');

export function resolveReference(value, document) {
  if (!value?.$ref) return value;
  return value.$ref.slice(2).split('/').map(part => part.replace(/~1/g, '/').replace(/~0/g, '~'))
    .reduce((node, key) => node?.[key], document);
}

function hasPartialSchema(value, document, visited = new Set()) {
  if (!value || typeof value !== 'object') return false;
  if (value['x-schema-status'] === 'partial') return true;
  if (value.$ref && !visited.has(value.$ref)) {
    const next = new Set(visited); next.add(value.$ref);
    if (hasPartialSchema(resolveReference(value, document), document, next)) return true;
  }
  return Object.entries(value).some(([key, child]) => key !== '$ref' && hasPartialSchema(child, document, visited));
}

export function responseGaps(groups) {
  return Object.entries(groups).flatMap(([group, document]) => Object.entries(document.paths).flatMap(([path, item]) =>
    methods.filter(method => item[method]).flatMap(method => Object.entries(item[method].responses ?? {}).flatMap(([status, raw]) => {
      const response = resolveReference(raw, document);
      const schema = response?.content?.['application/json']?.schema;
      // 204 和无正文的错误说明合法；统计成功 JSON 缺口与明确标记的未完成结构。
      if (/^2\d\d$/.test(status) && status !== '204' && !response?.content) {
        return [{ group, method, path, status, reason: '成功响应尚无数据 Schema' }];
      }
      return schema && hasPartialSchema(schema, document)
        ? [{ group, method, path, status, reason: '业务数据字段尚未完整定义' }] : [];
    }))));
}

export function responseValidator(document, path, method, status) {
  const ajv = new Ajv({ strict: false, allErrors: true });
  addFormats(ajv);
  const id = 'urn:howstack:response';
  ajv.addSchema({ ...document, $id: id });
  let response = resolveReference(document.paths[path]?.[method]?.responses?.[status], document);
  if (!response?.content?.['application/json']?.schema) throw new Error(`没有 JSON 响应契约：${method} ${path} ${status}`);
  // 以完整 OpenAPI 为引用根，保留跨组件引用，不能仅复制单个响应 Schema。
  const responsePath = document.paths[path][method].responses[status].$ref
    ?? pointer(['paths', path, method, 'responses', status]);
  return ajv.compile({ $ref: id + responsePath + '/content/application~1json/schema' });
}
