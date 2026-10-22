import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

await test('the retired companion directs operators to the supported Laravel listener', () => {
    const result = spawnSync(
        process.execPath,
        [new URL('./server.mjs', import.meta.url).pathname, '--help'],
        {
            encoding: 'utf8',
            timeout: 3000,
            env: {
                ...process.env,
                CHATGPT_COMPANION_PROXY_TOKEN:
                    'fictional-secret-must-not-print',
            },
        },
    );

    assert.equal(result.status, 1);
    assert.match(result.stderr, /retired/i);
    assert.match(result.stderr, /php artisan openai:oauth-listen/);
    assert.doesNotMatch(
        result.stdout + result.stderr,
        /fictional-secret-must-not-print/,
    );
});
