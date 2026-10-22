import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

for (const name of ['core', 'redis', 'runtime', 'cli', 'laravel']) {
    test(`${name} supplies PHPUnit configuration without Pest's generated fallback`, () => {
        const xml = readFileSync(`packages/${name}/phpunit.xml`, 'utf8');
        assert.match(xml, /bootstrap="vendor\/autoload\.php"/);
        assert.match(xml, /<directory>tests\/<\/directory>/);
        assert.match(xml, /<directory>src\/<\/directory>/);
    });
}
