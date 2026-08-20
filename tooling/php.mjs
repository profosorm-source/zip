#!/usr/bin/env node
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: process.pid } }));
await php.mount('/workspace', createNodeFsMountHandler(process.cwd()));
php.chdir('/workspace/runtime');
const translated = process.argv.slice(2).map(arg => {
  if (arg.startsWith(process.cwd())) return `/workspace${arg.slice(process.cwd().length)}`;
  if (!arg.startsWith('-') && (arg.endsWith('.php') || arg.startsWith('vendor/'))) return `/workspace/runtime/${arg.replace(/^runtime\//, '')}`;
  return arg;
});
const response = await php.cli(['php', ...translated], { cwd: '/workspace/runtime', env: process.env });
const [stdout, stderr, exitCode] = await Promise.all([response.stdoutBytes, response.stderrText, response.exitCode]);
process.stdout.write(Buffer.from(stdout));
if (stderr) process.stderr.write(stderr);
process.exit(exitCode);
