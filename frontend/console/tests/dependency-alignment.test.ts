import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

interface PackageManifest {
  dependencies: Record<string, string>
  devDependencies: Record<string, string>
}

function manifest(relative: string): PackageManifest {
  return JSON.parse(readFileSync(new URL(relative, import.meta.url), 'utf8')) as PackageManifest
}

test('与后台共同使用的依赖声明保持一致，保留规范要求的 React 19', () => {
  const admin = manifest('../../admin/package.json')
  const console = manifest('../package.json')
  const reactExceptions = new Set(['react', 'react-dom', '@types/react', '@types/react-dom'])
  for (const group of ['dependencies', 'devDependencies'] as const) {
    for (const [name, version] of Object.entries(console[group])) {
      if (name in admin[group] && !reactExceptions.has(name)) assert.equal(version, admin[group][name], `${name} 与后台不一致`)
    }
  }
  assert.match(console.dependencies.react, /^\^19\./)
  assert.equal(console.dependencies.react, console.dependencies['react-dom'])
})
