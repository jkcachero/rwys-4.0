console.error(
    'The experimental device-code companion is retired. Stop any older companion process, then run php artisan openai:oauth-listen from the Laravel checkout on the browser machine. Connect through Settings > Connections. No credentials were read and no network listener was started.',
);
process.exitCode = 1;
