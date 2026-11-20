import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const template = readFileSync(new URL('./nginx-origin.conf.template', import.meta.url), 'utf8')

test('ERP Production missing versioned JS/CSS returns static 404 without consuming PHP-FPM', () => {
    for (const prefix of ['/assets/', '/build/assets/']) {
        const escaped = prefix.replaceAll('/', '\\/')
        const pattern = new RegExp('location \\^~ ' + escaped + ' \\{\\s*try_files \\$uri =404;\\s*\\}')
        assert.match(template, pattern, prefix + ' must short-circuit missing assets')
    }
    assert.match(template, /location \/ \{\s*try_files \$uri \$uri\/ \/index\.php\?\$query_string;/)
})
