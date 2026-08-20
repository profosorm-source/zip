#!/usr/bin/env node
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const port = Number(process.env.PORT || 8000);
const hostRoot = path.resolve(process.cwd(), 'runtime');
const publicRoot = path.join(hostRoot, 'public');
const vfsRoot = '/workspace/runtime';
const php = new PHP(await loadNodeRuntime('8.4', {
  emscriptenOptions: { processId: process.pid },
}));
await php.mount('/workspace', createNodeFsMountHandler(process.cwd()));

const mime = {
  '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif',
  '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2',
};

let requestQueue = Promise.resolve();
function collect(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', c => chunks.push(c)); req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || `localhost:${port}`}`);
  const decoded = decodeURIComponent(url.pathname);
  const candidate = path.resolve(publicRoot, `.${decoded}`);
  if (candidate.startsWith(publicRoot + path.sep) && fs.existsSync(candidate) && fs.statSync(candidate).isFile() && !candidate.endsWith('.php')) {
    res.setHeader('Content-Type', mime[path.extname(candidate).toLowerCase()] || 'application/octet-stream');
    fs.createReadStream(candidate).pipe(res); return;
  }

  const body = await collect(req);
  requestQueue = requestQueue.then(async () => {
    const headers = Object.fromEntries(Object.entries(req.headers).map(([k, v]) => [k, Array.isArray(v) ? v.join(', ') : (v || '')]));
    const result = await php.run({
      scriptPath: `${vfsRoot}/public/index.php`, relativeUri: `${url.pathname}${url.search}`,
      method: req.method || 'GET', headers, body: body.length ? body : undefined,
      protocol: 'http', env: process.env,
      $_SERVER: {
        DOCUMENT_ROOT: `${vfsRoot}/public`, SCRIPT_FILENAME: `${vfsRoot}/public/index.php`,
        SCRIPT_NAME: '/index.php', PHP_SELF: '/index.php', REQUEST_URI: `${url.pathname}${url.search}`,
        REQUEST_METHOD: req.method || 'GET', HTTP_HOST: req.headers.host || `localhost:${port}`,
        SERVER_NAME: (req.headers.host || 'localhost').split(':')[0], SERVER_PORT: String(port),
        REMOTE_ADDR: req.socket.remoteAddress || '127.0.0.1', HTTPS: 'off',
      },
    });
    res.statusCode = result.httpStatusCode || 200;
    for (const [name, values] of Object.entries(result.headers || {})) {
      if (name.toLowerCase() === 'content-length') continue;
      res.setHeader(name, values.length === 1 ? values[0] : values);
    }
    res.end(Buffer.from(result.bytes || result.stdout || []));
  }).catch(err => {
    console.error(err);
    if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end(`PHP runtime error: ${err.message}`);
  });
});

server.listen(port, '0.0.0.0', () => console.log(`Chortke PHP 8.4 WASM preview listening on 0.0.0.0:${port}`));
