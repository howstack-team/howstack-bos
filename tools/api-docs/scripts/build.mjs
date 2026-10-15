import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { assemble, loadContracts } from './contracts.mjs';
import { responseGaps as findResponseGaps } from './responses.mjs';

const tool = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repo = resolve(tool, '../..');
const temporary = mkdtempSync(join(tmpdir(), 'howstack-openapi-'));
const hash = value => createHash('sha256').update(value).digest('hex').slice(0, 16);
const json = value => JSON.stringify(value, null, 2) + '\n';
const environment = { ...process.env, REDOCLY_TELEMETRY: 'off', REDOCLY_SUPPRESS_UPDATE_NOTICE: 'true' };
const redocly = join(tool, 'node_modules/@redocly/cli/bin/cli.js');
const check = process.argv.includes('--check');

try {
  const sourcesPath = join(repo, 'contracts/openapi');
  for (const file of readdirSync(sourcesPath).filter(file => /\.v\d+\.ya?ml$/.test(file)).sort()) {
    execFileSync(process.execPath, [redocly, 'bundle', join(sourcesPath, file), '--output', join(temporary, file), '--config', join(tool, 'redocly.yaml')],
      { env: environment, stdio: ['ignore', 'pipe', 'pipe'] });
  }
  const routes = JSON.parse(execFileSync(process.env.PHP_BINARY ?? 'php', [join(tool, 'scripts/routes.php')], { encoding: 'utf8' }));
  const { groups, coverage } = assemble(loadContracts(temporary), routes);
  if (coverage.missing.length || coverage.stale.length) {
    console.error(json(coverage));
    throw new Error('路由与契约不一致：请补充未记录路由或修正失效契约，不能通过空占位契约绕过检查。');
  }
  const output = join(repo, 'backend/resources/api-docs');
  const assets = join(repo, 'backend/public/api-docs/assets');
  const generated = new Map();
  for (const [group, specification] of Object.entries(groups)) {
    const target = join(temporary, `${group}.json`);
    writeFileSync(target, json(specification));
    if (Object.keys(specification.paths).length) {
      execFileSync(process.execPath, [redocly, 'lint', target, '--config', join(tool, 'redocly.yaml'), '--format', 'stylish'],
        { env: environment, stdio: ['ignore', 'pipe', 'pipe'] });
    }
    generated.set(`specs/${group}.json`, json(specification));
  }
  const scalarRoot = join(tool, 'node_modules/@scalar/api-reference/dist/browser');
  const scalar = readFileSync(join(scalarRoot, 'standalone.js'));
  const scalarVersion = JSON.parse(readFileSync(join(tool, 'node_modules/@scalar/api-reference/package.json'), 'utf8')).version;
  const scalarName = `scalar-${scalarVersion}-${hash(scalar)}`;
  const sources = Object.entries(groups).filter(([, spec]) => Object.keys(spec.paths).length).map(([slug, spec], index) => ({
    slug, title: spec.info.title, url: `/api-docs/specs/${slug}.json`, default: index === 0,
  }));
  const script = readFileSync(join(tool, 'src/reference.js'), 'utf8').replace('/* __BUILD_CONFIGURATION__ */', `const documentationSources = ${JSON.stringify(sources)};`);
  const css = readFileSync(join(tool, 'src/reference.css'), 'utf8');
  const scriptName = `reference-${hash(script)}.js`, cssName = `reference-${hash(css)}.css`;
  const responseGaps = findResponseGaps(groups);
  const allowedGaps = JSON.parse(readFileSync(join(tool, 'response-gaps.v1.json'), 'utf8'));
  const gapKey = gap => [gap.group, gap.method, gap.path, gap.status].join(' ');
  const newGaps = responseGaps.filter(gap => !allowedGaps.includes(gapKey(gap)));
  if (newGaps.length) throw new Error(`新增接口响应缺少完整 Schema：${newGaps.map(gapKey).join(', ')}`);
  generated.set('coverage.json', json({ ...coverage, responseGaps }));
  generated.set('index.html', readFileSync(join(tool, 'src/index.html'), 'utf8')
    .replace('__REFERENCE_CSS__', cssName)
    .replace('__SCALAR_DIRECTORY__', scalarName)
    .replace('__REFERENCE_JS__', scriptName));
  for (const [relative, contents] of generated) {
    const path = join(output, relative);
    if (check) {
      if (!existsSync(path) || readFileSync(path, 'utf8') !== contents) throw new Error(`${relative} 与源码不一致，请重新构建 API 文档。`);
    } else {
      mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, contents);
    }
  }
  if (!check) {
    mkdirSync(assets, { recursive: true });
    // 同次构建清理过期哈希目录；整个目录只存文档资源，不包含任何原始契约。
    for (const file of readdirSync(assets)) rmSync(join(assets, file), { recursive: true, force: true });
    cpSync(scalarRoot, join(assets, scalarName), { recursive: true, filter: source => !source.endsWith('.map') && !source.endsWith('standalone.esm.js') });
    writeFileSync(join(assets, scriptName), script); writeFileSync(join(assets, cssName), css);
  } else if (!existsSync(join(assets, scalarName, 'standalone.js')) || !existsSync(join(assets, scriptName)) || !existsSync(join(assets, cssName))) {
    throw new Error('本地文档静态资源缺失，请重新构建。');
  }
  // 不只检查主文件存在：动态调试模块缺失或被修改也必须阻断发行。
  function verifyDirectory(source, destination) {
    for (const entry of readdirSync(source, { withFileTypes: true })) {
      if (entry.name.endsWith('.map') || entry.name === 'standalone.esm.js') continue;
      const expected = join(source, entry.name), actual = join(destination, entry.name);
      if (entry.isDirectory()) verifyDirectory(expected, actual);
      else if (!existsSync(actual) || !readFileSync(expected).equals(readFileSync(actual))) throw new Error(`文档资源缺失或不一致：${entry.name}`);
    }
  }
  verifyDirectory(scalarRoot, join(assets, scalarName));
  for (const [name, contents] of [[scriptName, script], [cssName, css]]) {
    if (readFileSync(join(assets, name), 'utf8') !== contents) throw new Error(`文档资源与源码不一致：${name}`);
  }
  const license = readFileSync(join(tool, 'licenses/Scalar-MIT.txt'));
  if (!check) writeFileSync(join(assets, scalarName, 'LICENSE.txt'), license);
  else if (!existsSync(join(assets, scalarName, 'LICENSE.txt')) || !license.equals(readFileSync(join(assets, scalarName, 'LICENSE.txt')))) throw new Error('Scalar 许可证缺失或不一致');
  console.log(`API 文档：${coverage.covered}/${coverage.routes} 条注册路由已覆盖；${coverage.operations} 个契约操作；响应结构待完善 ${responseGaps.length} 个。`);
} catch (error) {
  if (error.stdout) console.error(error.stdout.toString());
  if (error.stderr) console.error(error.stderr.toString());
  console.error(error.message); process.exitCode = 1;
} finally { rmSync(temporary, { recursive: true, force: true }); }
