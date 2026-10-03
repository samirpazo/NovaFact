async (page) => {
  const base = 'http://127.0.0.1:18083';
  const headers = { Authorization: 'Bearer isolated-gre-demo-token', Accept: 'application/json' };
  // Replace IDs with the IDs returned by gre.playwright.js. Do not resubmit while pending.
  const ids = [1, 2, 3, 4, 5];
  const results = [];
  for (const id of ids) {
    const response = await page.request.get(`${base}/api/facturacion/submissions/${id}`, { headers });
    if (response.status() !== 200) throw Error(`Missing submission ${id}`);
    const body = await response.json();
    const artifacts = [];
    for (const field of ['xml_url', 'zip_url', 'pdf_url', 'cdr_url']) {
      if (!body[field]) continue;
      const file = await page.request.get(body[field], { headers });
      const bytes = await file.body();
      if (file.status() !== 200 || bytes.length === 0) throw Error(`Missing ${field}`);
      artifacts.push({ type: field.replace('_url', ''), bytes: bytes.length, http: 200 });
    }
    const ticket = await page.request.get(`${base}/api/facturacion/historial-guia/${body.ticket}`, { headers });
    if (ticket.status() !== 200) throw Error('Ticket lookup failed');
    if (['accepted', 'accepted_with_observations'].includes(body.status) && (!body.cdr_url || !body.pdf_url || body.sunat_code !== '0')) {
      throw Error('Accepted GRE lacks verified fiscal artifacts');
    }
    results.push({ submission: body, artifacts, ticket_http: 200 });
  }
  return results;
}
