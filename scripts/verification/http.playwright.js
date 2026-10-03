async (page) => {
  const base = 'http://127.0.0.1:18081';
  // Public fixture token, valid only in the isolated readiness_http database.
  const headers = { Accept: 'application/json', Authorization: 'Bearer isolated-demo-token' };
  const checks = [
    ['GET', '/up', {}, 200],
    ['GET', '/docs', {}, 200],
    ['GET', '/docs/postman', {}, 200],
    ['GET', '/api/facturacion/configuracion/empresa', {}, 401],
    ['GET', '/api/facturacion/configuracion/empresa', { headers }, 200],
    ['GET', '/api/facturacion/configuracion/empresa', { headers: { ...headers, 'X-Company-Id': '999' } }, 403],
    ['GET', '/api/facturacion/configuracion/empresa', { headers: { ...headers, 'X-Client-Code': 'other' } }, 403],
    ['GET', '/api/facturacion/operations/999', { headers }, 404],
    ['POST', '/api/facturacion/emitir-factura', { headers, data: {} }, 422],
    ['POST', '/api/facturacion/boletas/baja', { headers, data: {} }, 422],
  ];
  const results = [];
  for (const [method, path, options, expected] of checks) {
    const response = await page.request.fetch(base + path, { method, ...options });
    results.push({ method, path, status: response.status(), expected });
    if (response.status() !== expected) throw new Error(`${method} ${path}: ${response.status()} != ${expected}`);
  }
  await page.goto(base + '/docs');
  if (!(await page.title()).includes('Documentación API')) throw new Error('Missing API documentation');
  return results;
}
