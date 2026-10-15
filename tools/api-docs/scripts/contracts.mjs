import { readFileSync, readdirSync } from 'node:fs';
import { basename, join } from 'node:path';
import YAML from 'yaml';

export const methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
const token = /\{([a-zA-Z_][\w]*)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/g;
export const normalizePath = path => path.replace(token, '{$1}');
const escape = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

export function matchesRoute(path, route) {
  if (normalizePath(route) === path) return true;
  let index = 0, pattern = '^';
  for (const part of route.matchAll(token)) {
    pattern += escape(route.slice(index, part.index));
    pattern += `(?:${escape(`{${part[1]}}`)}|(?:${part[2] ?? '[^/{}]+'}))`;
    index = part.index + part[0].length;
  }
  return new RegExp(pattern + escape(route.slice(index)) + '$').test(path);
}

export function transform(value, namespace, version) {
  if (Array.isArray(value)) return value.map(item => transform(item, namespace, version));
  if (!value || typeof value !== 'object') return value;
  const result = {};
  for (const [key, item] of Object.entries(value)) {
    if (key === 'nullable' && version.startsWith('3.0')) continue;
    if (key === '$ref') {
      if (!item.startsWith('#/components/')) throw new Error(`只允许本地 components 引用：${item}`);
      result[key] = item.replace(/^#\/components\/([^/]+)\/([^/]+)/, `#/components/$1/${namespace}_$2`);
    } else result[key] = transform(item, namespace, version);
  }
  if (value.nullable === true && version.startsWith('3.0')) {
    if (result.type) {
      result.type = [result.type, 'null'];
      if (result.enum && !result.enum.includes(null)) result.enum.push(null);
    } else if (result.$ref || result.allOf || result.oneOf || result.anyOf) {
      return { anyOf: [result, { type: 'null' }] };
    } else result.type = ['object', 'array', 'string', 'number', 'boolean', 'null'];
  }
  return result;
}

export function loadContracts(directory) {
  return readdirSync(directory).filter(file => /\.v\d+\.ya?ml$/.test(file)).sort().map(file => {
    const parsed = YAML.parseDocument(readFileSync(join(directory, file), 'utf8'), { uniqueKeys: true });
    if (parsed.errors.length) throw new Error(`${file}: ${parsed.errors.map(error => error.message).join('\n')}`);
    const document = parsed.toJS();
    return { file, document: expandPathRefs(document, document), namespace: basename(file).replace(/[^a-zA-Z0-9]/g, '_') };
  });
}

export function expandPathRefs(value, root, visited = new Set()) {
  if (Array.isArray(value)) return value.map(item => expandPathRefs(item, root, visited));
  if (!value || typeof value !== 'object') return value;
  if (value.$ref?.startsWith('#/') && !value.$ref.startsWith('#/components/')) {
    if (visited.has(value.$ref)) throw new Error(`循环 path 引用：${value.$ref}`);
    const target = value.$ref.slice(2).split('/').map(part => part.replace(/~1/g, '/').replace(/~0/g, '~')).reduce((node, key) => node?.[key], root);
    if (!target) throw new Error(`引用不存在：${value.$ref}`);
    const next = new Set(visited); next.add(value.$ref);
    const { $ref, ...siblings } = value;
    return expandPathRefs({ ...target, ...siblings }, root, next);
  }
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, expandPathRefs(item, root, visited)]));
}

export function groupFor(path) {
  if (path.startsWith('/api/admin/')) return 'admin';
  if (path.startsWith('/api/console/')) return 'console';
  if (path.startsWith('/api/public/') || path.startsWith('/api/install/')) return 'public';
  if (path.startsWith('/api/partner/')) return 'partner';
  throw new Error(`未分类的接口：${path}`);
}

const tags = {
  'business-analysis': '经营分析',
  auth: '认证', administrators: '组织与权限', roles: '组织与权限', departments: '组织与权限', permissions: '组织与权限',
  profile: '个人设置', members: '会员', 'member-groups': '会员', 'member-levels': '会员', 'member-catalog': '会员',
  'real-name-authentications': '实名认证', orders: '订单', finance: '财务', wallet: '财务', 'service-instances': '服务实例',
  tickets: '工单', 'ticket-catalog': '工单', content: '内容', channels: '通道', 'business-plugins': '插件',
  settings: '系统设置', system: '系统版本', 'official-platform': '授权与官网', 'system-updates': '系统更新',
  'application-center': '应用中心', 'provider-connections': '供应连接', 'compute-resources': '云服务器',
  'cloud-products': '云服务器', 'cloud-servers': '云服务器', 'message-configurations': '消息配置',
  'message-scenes': '消息场景', messages: '消息中心', 'message-logs': '消息日志', 'operation-logs': '审计', 'login-logs': '审计',
  website: '官网与主题', storage: '存储', install: '在线安装',
};
export function tagFor(path) { return tags[path.replace(/^\/api\/(?:admin|console|public|partner)\/v\d+\//, '').split('/')[0]] ?? '在线安装'; }

function mergeParameters(left = [], right = []) {
  const parameters = new Map();
  for (const param of [...left, ...right]) parameters.set(param.$ref ?? `${param.in}:${param.name}`, param);
  return [...parameters.values()];
}

export function assemble(sources, routes) {
  const groups = Object.fromEntries(['admin', 'console', 'public', 'partner'].map(group => [group, {
    openapi: '3.1.0', info: { title: { admin: '运营后台 API', console: '会员控制台 API', public: '公开与安装 API', partner: '合作方 API' }[group], version: '1.0.0',
      description: '统一返回 code、message、data、request_id；金额以整数分表示。文档访问权限不会授予业务操作权限。实际请求仍执行各接口的认证、权限、同源和归属校验。' },
    servers: [{ url: '/' }], paths: {}, components: { securitySchemes: {}, schemas: {}, responses: {}, parameters: {}, requestBodies: {} },
  }]));
  const usedIds = new Set(), documented = [], stale = [];
  // 查询补充契约必须最后合并，不能覆盖业务响应与请求结构。
  for (const source of [...sources].sort((a, b) => Number(a.file.startsWith('admin-list-filters')) - Number(b.file.startsWith('admin-list-filters')))) {
    const { document, namespace, file } = source;
    if (!/^3\.[01]\./.test(document.openapi)) throw new Error(`${file}: 不支持的 OpenAPI 版本`);
    const components = transform(document.components ?? {}, namespace, document.openapi);
    const prefix = (document.servers?.[0]?.url ?? '/').replace(/\/$/, '');
    for (const [originalPath, pathItem] of Object.entries(document.paths ?? {})) {
      const path = normalizePath(originalPath.startsWith('/api/') ? originalPath : prefix + originalPath);
      const group = groups[groupFor(path)];
      for (const [type, entries] of Object.entries(components)) {
        group.components[type] ??= {};
        for (const [name, entry] of Object.entries(entries)) {
          if (type === 'securitySchemes') group.components[type][`${namespace}_${name}`] = entry;
          else group.components[type][`${namespace}_${name}`] = entry;
        }
      }
      for (const method of methods.filter(method => pathItem[method])) {
        const route = routes.find(route => route.method === method && matchesRoute(path, route.path));
        if (!route) { stale.push({ file, method, path }); continue; }
        const operation = transform(pathItem[method], namespace, document.openapi);
        const inherited = transform(pathItem.parameters ?? [], namespace, document.openapi);
        operation.parameters = mergeParameters(inherited, operation.parameters);
        operation.parameters = operation.parameters.map(parameter => {
          if (!parameter.$ref) return parameter;
          const name = parameter.$ref.split('/').at(-1);
          const resolved = group.components.parameters?.[name];
          if (!resolved) throw new Error(`参数引用不存在：${parameter.$ref}`);
          return structuredClone(resolved);
        });
        const security = pathItem[method].security ?? document.security;
        if (security) operation.security = security.map(requirement => Object.fromEntries(Object.entries(requirement).map(([name, scopes]) => [`${namespace}_${name}`, scopes])));
        else {
          const kind = route.middleware.some(middleware => middleware.endsWith('AdminAuthMiddleware')) ? 'admin' :
            route.middleware.some(middleware => middleware.endsWith('MemberAuthMiddleware')) ? 'member' : null;
          if (kind) {
            group.components.securitySchemes[`${kind}Cookie`] = { type: 'apiKey', in: 'cookie', name: `hlyq_${kind}_token` };
            group.components.securitySchemes[`${kind}Bearer`] = { type: 'http', scheme: 'bearer', bearerFormat: 'JWT' };
            operation.security = [{ [`${kind}Cookie`]: [] }, { [`${kind}Bearer`]: [] }];
          } else operation.security = [];
        }
        operation.tags = [tagFor(path)];
        operation['x-source-contract'] = file;
        operation['x-route-name'] = route.name;
        // 路由正则约束同步为必填 path 参数；文档已定义的语义/enum 保留。
        for (const part of path.matchAll(/\{([^}]+)\}/g)) {
          if (!operation.parameters.some(parameter => parameter.in === 'path' && parameter.name === part[1])) {
            operation.parameters.push({ name: part[1], in: 'path', required: true, schema: { type: 'string' } });
          }
        }
        group.paths[path] ??= {};
        const existing = group.paths[path][method];
        if (existing) {
          if (!file.startsWith('admin-list-filters')) throw new Error(`重复接口：${method} ${path} (${existing['x-source-contract']}, ${file})`);
          existing.parameters = mergeParameters(existing.parameters, operation.parameters);
          existing.description = [existing.description, document.info.description].filter(Boolean).join('\n\n');
          continue;
        }
        operation.operationId ??= `${method}_${path.replace(/[^a-zA-Z0-9]+/g, '_')}`;
        if (usedIds.has(operation.operationId)) throw new Error(`重复 operationId：${operation.operationId}`);
        usedIds.add(operation.operationId);
        group.paths[path][method] = operation;
        documented.push({ method, path, file });
      }
    }
  }
  const missing = routes.filter(route => !documented.some(operation => operation.method === route.method && matchesRoute(operation.path, route.path)));
  const coverage = { routes: routes.length, operations: documented.length, covered: routes.length - missing.length,
    missing: missing.map(({ method, path, name }) => ({ method, path, name })), stale };
  for (const group of Object.values(groups)) {
    // 各模块复用相同的 Cookie/Bearer 认证，文档调试面板不重复展示十余个同义方案。
    const equivalents = new Map(), names = new Map(), schemes = {};
    for (const path of Object.values(group.paths)) {
      for (const method of methods.filter(method => path[method])) {
        path[method].security = (path[method].security ?? []).map(requirement => Object.fromEntries(Object.entries(requirement).map(([name, scopes]) => {
          if (!names.has(name)) {
            const definition = group.components.securitySchemes[name];
            if (!definition) throw new Error(`认证定义不存在：${name}`);
            const { description, ...configuration } = definition;
            const fingerprint = JSON.stringify(configuration);
            const candidate = definition.in === 'cookie' && /hlyq_(admin|member)_token/.test(definition.name)
              ? `${definition.name.includes('admin') ? 'admin' : 'member'}Cookie` : definition.type === 'http' && definition.scheme === 'bearer' ? `${group.info.title.includes('会员') ? 'member' : 'admin'}Bearer` : name;
            const key = equivalents.get(fingerprint) ?? (schemes[candidate] ? name : candidate);
            names.set(name, key); equivalents.set(fingerprint, key); schemes[key] = definition;
          }
          return [names.get(name), scopes];
        })));
      }
    }
    group.components.securitySchemes = schemes;
    group.tags = [...new Set(Object.values(group.paths).flatMap(path => methods.flatMap(method => path[method]?.tags ?? [])))].sort().map(name => ({ name }));
  }
  return { groups, coverage };
}
