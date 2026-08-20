#!/usr/bin/env node
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: process.pid } }));
await php.mount('/workspace', createNodeFsMountHandler(process.cwd()));
const args = process.argv.slice(2);
let result;
if (args[0] === '-r') {
  result = await php.run({ code: `<?php ${args[1] ?? ''}` });
} else if (args[0]) {
  const hostPath = args[0].startsWith(process.cwd()) ? args[0].slice(process.cwd().length) : `/${args[0].replace(/^\.\//, '')}`;
  const scriptPath = `/workspace${hostPath}`;
  result = await php.run({ scriptPath, relativeUri: scriptPath, env: process.env });
} else {
  console.error('Usage: php-cli.mjs [-r code] <script.php>');
  process.exit(2);
}
if (result.stdout !== undefined) process.stdout.write(Buffer.from(result.stdout));
else if (result.bytes !== undefined) process.stdout.write(Buffer.from(result.bytes));
else if (result.text !== undefined) process.stdout.write(String(result.text));
if (result.stderr?.length) process.stderr.write(Array.isArray(result.stderr) ? result.stderr.join('\n') + '\n' : String(result.stderr));
process.exit(result.exitCode ?? 0);
