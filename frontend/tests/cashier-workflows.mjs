// Real-browser frontend checks against deterministic API fixtures.
// BUILD_DIR points to `vite build` output. PUPPETEER_MODULE may point to an installed Puppeteer module.
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdtemp } from 'node:fs/promises'
import { extname, resolve } from 'node:path'
import { tmpdir } from 'node:os'

const module = await import(process.env.PUPPETEER_MODULE || 'puppeteer-core')
const puppeteer = module.puppeteer || module.default
const output = await mkdtemp(`${tmpdir()}/passover-browser-`)
const root = resolve(process.env.BUILD_DIR || 'dist')
const server = createServer(async (request, response) => {
  try {
    const path = new URL(request.url, 'http://localhost').pathname
    const file = path.startsWith('/assets/') ? resolve(root, `.${path}`) : resolve(root, 'index.html')
    if (!file.startsWith(`${root}/`)) { response.writeHead(403).end(); return }
    response.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html' })[extname(file)] || 'application/octet-stream')
    response.end(await readFile(file))
  } catch { response.writeHead(404).end() }
})
await new Promise((done, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', done) })
const base = `http://127.0.0.1:${server.address().port}`
let browser
try {
  browser = await puppeteer.launch({ executablePath: process.env.CHROME_EXECUTABLE, headless: true, userDataDir: `${output}/profile`, args: ['--no-sandbox', '--disable-dev-shm-usage'] })
  const page = await browser.newPage()
  await page.setViewport({ width: 1366, height: 900 })
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  const order = { id: 1, order_number: 'ORD-SMOKE', status: 'pending', subtotal: '250.00', submitted_at: new Date().toISOString(), table_session: { id: 1, table: { table_number: '1' } }, items: [{ id: 1, quantity: 2, menu_item: { name: 'Latte' }, special_instruction: 'No sugar' }] }
  const table = { id: 1, table_number: '1', capacity: 4, status: 'occupied', active_session: { id: 1 } }
  const slip = { id: 1, ticket_number: 'KOT-SMOKE', order_number: order.order_number, table_number: '1', order_time: order.submitted_at, customer_note: 'Serve together', items: [{ quantity: 2, menu_item_name: 'Latte', special_instruction: 'No sugar' }], print_count: 0 }
  let postedOrder, allowClose = false, trackingStatus = 'pending', statusReads = 0
  await page.setRequestInterception(true)
  page.on('request', async (request) => {
    const url = new URL(request.url())
    if (!request.url().startsWith(base)) { await request.abort(); return }
    if (!url.pathname.startsWith('/api/')) { await request.continue(); return }
    const path = url.pathname.slice(4)
    const method = request.method()
    let data, code = 200
    if (path === '/user') data = { id: 1, name: 'Test Cashier', email: 'cashier@example.test', role: 'cashier', is_active: true }
    else if (path === '/cashier/orders') data = [structuredClone(order)]
    else if (path === '/cashier/tables') data = [structuredClone(table)]
    else if (path === '/categories') data = [{ id: 1, name: 'Coffee', is_active: true }]
    else if (path === '/cashier/menu-items' || path === '/menu-items') data = [{ id: 1, category_id: 1, category: { id: 1, name: 'Coffee' }, name: 'Latte', price: '125.00', is_available: true, inventory: { quantity: 20 } }]
    else if (path === '/cashier/tables/1/orders') { postedOrder = JSON.parse(request.postData()); data = structuredClone(order); code = 201 }
    else if (path === '/cashier/orders/1/confirm') { order.status = 'confirmed'; order.kitchen_ticket = { id: 1 }; data = structuredClone(order) }
    else if (path === '/kitchen-order-slips/1') data = structuredClone(slip)
    else if (path === '/kitchen-order-slips/1/printed') { slip.print_count++; data = structuredClone(slip) }
    else if (path === '/cashier/table-sessions/1/close') {
      if (!allowClose) { await request.respond({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Settle all bills before closing the dining session.' }) }); return }
      table.status = 'available'; table.active_session = null; data = { status: 'closed' }
    }
    else if (path.startsWith('/customer/tables/')) data = { table_number: '1', capacity: 4, accepts_orders: true }
    else if (path.endsWith('/status')) { statusReads++; data = { order_number: order.order_number, status: trackingStatus } }
    else { errors.push(`Unexpected request ${method} ${path}`); code = 404; data = {} }
    await request.respond({ status: code, contentType: 'application/json', body: JSON.stringify({ data, success: code < 400 }) })
  })
  async function click(text) {
    await page.waitForFunction((label) => [...document.querySelectorAll('button')].some((button) => button.textContent.trim() === label && !button.disabled), {}, text)
    for (const button of await page.$$('button')) {
      if (await button.evaluate((el, label) => el.textContent.trim() === label, text)) { await button.click(); return }
    }
    throw Error(`Missing button ${text}`)
  }
  console.log('Checking cashier order entry')
  await page.goto(`${base}/cashier/orders`)
  await click('New order')
  await page.waitForSelector('dialog select')
  await page.select('dialog select', '1')
  await page.$eval('dialog input[type=number]', (input) => { input.focus(); input.select() })
  await page.type('dialog input[type=number]', '2')
  await click('Create order')
  await page.waitForFunction(() => !document.querySelector('dialog'))
  assert.equal(postedOrder.items[0].quantity, 2)
  assert.equal(postedOrder.items[0].menu_item_id, 1)
  assert.equal(postedOrder.items[0].unit_price, undefined)
  await click('Confirm order')
  await click('All')
  console.log('Checking kitchen slip printing')
  await click('Kitchen slip')
  await page.waitForSelector('.cashier-kitchen-slip li')
  assert.match(await page.$eval('dialog', (el) => el.innerText), /No sugar/)
  await page.evaluate(() => { window.printCalls = 0; window.print = () => { window.printCalls++ } })
  await click('Print kitchen slip')
  await page.waitForFunction(() => window.printCalls === 1)
  assert.equal(slip.print_count, 1)
  await page.emulateMediaType('print')
  assert.equal(await page.$eval('#root', (el) => getComputedStyle(el).display), 'none')
  assert.equal(await page.$eval('.cashier-print-document', (el) => getComputedStyle(el).display), 'block')
  assert.equal(await page.$eval('.cashier-print-document .cashier-slip-heading', (el) => getComputedStyle(el).display), 'block')
  await page.pdf({ path: `${output}/kitchen-slip.pdf`, width: '80mm', printBackground: true })
  await page.emulateMediaType('screen')
  await page.screenshot({ path: `${output}/kitchen-slip.png` })
  await click('Close')
  console.log('Checking session closure')
  await page.goto(`${base}/cashier/tables`)
  await click('Close session')
  // The confirmation dialog and table card have the same action label; use the dialog action explicitly.
  await page.$eval('dialog .a-form-footer button.primary', (button) => button.click())
  await page.waitForFunction(() => document.querySelector('dialog')?.innerText.includes('Settle all bills'))
  assert.equal(table.status, 'occupied')
  allowClose = true
  await page.$eval('dialog .a-form-footer button.primary', (button) => button.click())
  await page.waitForFunction(() => !document.querySelector('dialog'))
  await page.waitForFunction(() => document.querySelector('.a-table-card')?.innerText.includes('Available'))
  assert.equal(table.active_session, null)
  await page.setViewport({ width: 390, height: 844 })
  console.log('Checking mobile order entry')
  await page.goto(`${base}/cashier/orders`)
  await click('New order')
  await page.waitForSelector('.cashier-entry-items')
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
  await page.screenshot({ path: `${output}/mobile-order-entry.png` })
  await click('Cancel')
  console.log('Checking customer status propagation')
  await page.goto(`${base}/order/test-token`)
  const keys = await page.evaluate(() => Object.keys(sessionStorage))
  // Match the application's public tracking storage key without reaching into React internals.
  const source = await readFile(new URL('../src/customer/CustomerOrder.jsx', import.meta.url), 'utf8')
  const keyMatch = source.match(/const trackingKey = `([^`]+)`/)
  assert.ok(keyMatch)
  const trackingKey = keyMatch[1].replace('${token}', 'test-token')
  await page.evaluate((key) => sessionStorage.setItem(key, JSON.stringify({ tracking_token: '00000000-0000-4000-8000-000000000001', order_number: 'ORD-SMOKE', status: 'pending' })), trackingKey)
  await page.reload()
  await page.waitForFunction(() => document.querySelector('body')?.innerText.includes('Order submitted'))
  // Allow the initial status response, then change the simulated server state.
  await new Promise((done) => setTimeout(done, 250))
  const readsBefore = statusReads
  const start = Date.now()
  trackingStatus = 'confirmed'
  await page.waitForFunction((key) => JSON.parse(sessionStorage.getItem(key))?.status === 'confirmed', { timeout: 3000 }, trackingKey)
  const propagation = Date.now() - start
  assert.ok(statusReads > readsBefore)
  assert.ok(propagation < 3000)
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ result: 'passed', checks: ['cashier entry', 'confirmation', 'KOS preview and print', 'isolated print layout', 'blocked/unblocked closure', 'mobile order form', 'customer status propagation'], propagation_ms: propagation, output, fixture_storage_keys: keys.length }))
} finally {
  if (browser) await browser.close()
  server.closeAllConnections()
  await new Promise((done) => server.close(done))
}
