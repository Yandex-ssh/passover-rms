# Phase 13D — Formal Interactive Browser UAT Manual Checklist

Status: Awaiting manual execution by the local operator
Accounts prepared locally: `admin@gmail.com`, `cashier@gmail.com`
Passwords: intentionally not recorded

## Execution rules

- Use the local Pass-over gateway after starting the Pass-over Nginx service.
- Do not use production data, production URLs, final QR labels, or production credentials.
- Use only temporary records with a clear `UAT` prefix.
- Record browser console errors, failed requests, 401/403 responses, 404/500 responses, and screenshots for failures.
- Do not mark a case PASS unless it was actually executed in the browser.
- If a route or UI does not exist, record BLOCKED or FAIL according to the actual result; do not infer backend capability as browser support.
- For every case, record the result as PASS, FAIL, or BLOCKED and add evidence.

Result template for each case:

```text
Actual result:
Status: PASS / FAIL / BLOCKED
Evidence:
Notes:
```

## UAT data preparation

Before cases:

1. Open the Admin route and sign in with the temporary Admin account.
2. Record existing development counts for tables, categories, menu items, inventories, users, orders, transactions, payments, receipts, and kitchen tickets.
3. Create or identify clearly prefixed records:
   - `UAT Table`
   - `UAT Category`
   - `UAT Coffee`
   - `UAT Burger`
4. Restock UAT items to a known quantity and record the starting quantity.
5. Ensure the UAT table has an active TableSession using the existing application setup.
6. Obtain the UAT table QR token from the Admin QR display. Physical QR scanning is a separate environment case.
7. Keep the temporary Admin and Cashier accounts available until all tests finish.

## 13D-2 Authentication and role UAT

### UAT-AUTH-001
Actor: Admin
Route: `/admin`
Steps:
1. Open `/admin` in a fresh browser context.
2. Enter `admin@gmail.com` and the locally configured password.
3. Submit the login form.
4. Observe the resulting page and navigation.
Expected: Login succeeds; Admin dashboard loads; Admin name/role is shown; Admin navigation includes Users, Tables & QR, Menu & Categories, Inventory, Reports, and Dashboard; no stack trace appears.

### UAT-AUTH-002
Actor: Guest
Route: `/admin`
Steps:
1. Open `/admin` without an authenticated session.
2. Enter `admin@gmail.com` with an incorrect password.
3. Submit.
Expected: Login is rejected; a safe error is shown; no Admin page or session is granted; no stack trace appears.

### UAT-AUTH-003
Actor: Cashier
Route: `/cashier`
Steps:
1. Log out if necessary or use a fresh browser context.
2. Open `/cashier`.
3. Enter `cashier@gmail.com` and the locally configured password.
4. Submit.
Expected: Cashier login succeeds; Cashier queue loads; Cashier navigation shows queue, billing/payments, and receipts; Admin Users navigation is absent.

### UAT-AUTH-004
Actor: Cashier
Routes: `/admin`, `/admin/users`, `/admin/reports`
Steps:
1. While logged in as Cashier, navigate directly to each route.
2. Observe the page and browser network responses.
Expected: Admin pages are blocked or redirected; no Admin data or controls are exposed; corresponding Admin API requests are rejected with 403 if attempted.

### UAT-AUTH-005
Actor: Guest
Routes: `/cashier`, `/admin`, `/admin/users`
Steps:
1. Log out.
2. Open each route directly.
Expected: Staff pages are not usable without login; no queue, financial, dashboard, or staff data is shown.

### UAT-AUTH-006
Actor: Cashier and Admin
Routes: `/cashier`, `/admin/users`
Steps:
1. Log in as Cashier in one browser context and keep it open.
2. In a separate Admin-capable browser context, open `/admin/users`.
3. Deactivate `cashier@gmail.com`.
4. Return to the existing Cashier context without logging out or refreshing first.
5. Attempt to open `/cashier` data or perform an available Cashier operation.
Expected: Existing Cashier session is rejected on the next protected request; safe authentication handling is shown; no Cashier data mutation occurs.

### UAT-AUTH-007
Actor: Admin then Cashier
Route: `/admin/users`, then `/cashier`
Steps:
1. Reactivate `cashier@gmail.com` from `/admin/users`.
2. Open `/cashier` in the Cashier browser context.
3. Sign in again if required.
Expected: Reactivated Cashier can authenticate and access the Cashier queue.

### UAT-AUTH-008
Actor: Admin or Cashier
Route: `/admin` or `/cashier`
Steps:
1. Sign in.
2. Click Sign out.
3. Reopen the protected route and refresh.
Expected: Session ends; protected content is no longer accessible; login is required again.

## 13D-3 Customer UAT

Use `{qrToken}` as the token obtained from the UAT table QR display. Use a fresh customer browser context with no staff login.

### UAT-CUST-001
Actor: Customer
Route: `/order/{qrToken}`
Steps:
1. Open the route in a fresh browser context.
2. Do not log in.
3. Observe the table/menu page.
Expected: Customer page loads; no login is required; menu content is visible; customer context is usable.

### UAT-CUST-002
Actor: Customer
Route: `/order/{qrToken}`
Steps:
1. Open the customer route.
2. Select each visible category.
3. Compare displayed prices with the known UAT menu setup.
4. Confirm unavailable/out-of-stock items are not offered for ordering.
Expected: Categories filter correctly; available items and backend prices display; unavailable items cannot be ordered.

### UAT-CUST-003
Actor: Customer
Route: `/order/{qrToken}`
Steps:
1. Add a UAT item.
2. Increase and decrease its quantity.
3. Remove it by reducing quantity to zero.
4. Add it again.
5. Enter a special instruction and customer note.
6. Compare the displayed estimated subtotal with the selected item prices and quantities.
Expected: Cart controls work; instructions and note are retained; subtotal updates correctly; no login is requested.

### UAT-CUST-004
Actor: Customer
Route: `/order/{qrToken}`
Steps:
1. Add one or more available UAT items.
2. Submit the order.
3. Observe the confirmation screen.
Expected: Submission succeeds; order number is shown; status is pending/waiting for Cashier confirmation; cart resets according to the implemented UI; no inventory deduction is visible before confirmation.

### UAT-CUST-005
Actor: Customer and Cashier
Routes: customer `/order/{qrToken}`; Cashier `/cashier`
Steps:
1. Submit a valid customer order and keep the customer page open.
2. In the Cashier context, confirm the order.
3. Return to or observe the customer page while polling occurs.
Expected: Customer status changes from pending to confirmed; no internal database ID is exposed; only the safe customer tracking information is shown.

### UAT-CUST-006
Actor: Customer and Cashier
Routes: customer `/order/{qrToken}`; Cashier `/cashier`
Steps:
1. Submit a second valid customer order.
2. Reject it in the Cashier queue.
3. Observe the customer confirmation/tracking screen.
Expected: Customer sees the rejected state; tracking stops appropriately; safe error/status text is shown.

### UAT-CUST-007
Actor: Customer
Route: `/order/{qrToken}`
Steps:
1. Close or deactivate the UAT table session using the existing supported setup.
2. Open the customer route.
3. Submit an available item.
Expected: Submission is rejected with an understandable inactive-session message; no order is created.

### UAT-CUST-008
Actor: Customer
Route: `/order/invalid-uat-token`
Steps:
1. Open the invalid-token route.
Expected: Safe not-found response/page; no internal exception details are shown.

### UAT-CUST-009
Actor: Customer
Route: `/order/{qrToken}`
Steps:
1. Submit a valid order.
2. Refresh the browser while the confirmation/tracking page is visible.
3. Observe the restored page and polling behavior.
Expected: SessionStorage-backed tracking resumes for the saved order; status remains safe and no login is required.

## 13D-4 Cashier, kitchen, billing, and payment UAT

### UAT-CASH-001
Actor: Cashier
Route: `/cashier`
Steps:
1. Log in as Cashier.
2. Open or refresh the queue.
3. Locate the submitted UAT order.
Expected: Pending order appears with correct order number, table, items, quantities, and customer instructions/notes where displayed.

### UAT-CASH-002
Actor: Cashier
Route: `/cashier`
Steps:
1. Select the UAT order.
2. Inspect its detail panel.
Expected: Table and item details are accurate; no unexpected price mutation is visible.

### UAT-CASH-003
Actor: Cashier
Route: `/cashier`
Steps:
1. Confirm a pending UAT order.
2. Observe the queue after confirmation.
3. Supplement with database/API evidence only if needed for inventory, KitchenTicket, and DiningTransaction counts.
Expected: Confirmation succeeds; order leaves pending queue; inventory is deducted once; KitchenTicket and DiningTransaction are created.

### UAT-CASH-004
Actor: Cashier
Route: `/cashier`
Steps:
1. Attempt to confirm the same order again if a control remains or by refreshing/reopening it.
Expected: Duplicate confirmation is prevented; no second deduction, KitchenTicket, or transaction effect occurs.

### UAT-CASH-005
Actor: Cashier/Kitchen
Route: `/cashier` first; current frontend has no separate Kitchen Slip route
Steps:
1. Inspect the Cashier UI for a Kitchen Slip action or link.
2. If exposed, open the provided slip route and inspect the rendered slip.
3. If not exposed, record the missing browser route honestly.
Expected when exposed: ticket number, order, table, items, quantities, instructions, and note are visible; prices, payments, and receipt details are absent. If no UI route exists, record BLOCKED or requirement gap; do not claim browser verification from the backend endpoint alone.

### UAT-CASH-006
Actor: Cashier/Kitchen
Route: Kitchen Slip UI route if exposed; otherwise not available
Steps:
1. Trigger browser printing from the Kitchen Slip view if available.
2. Inspect print preview.
Expected when exposed: legible printer-friendly slip with irrelevant navigation hidden. Do not claim physical printer or silent printing.

### UAT-CASH-007
Actor: Customer and Cashier
Routes: customer `/order/{qrToken}`; Cashier `/cashier`
Steps:
1. Before paying the first confirmed transaction, submit another order from the same active table session.
2. Confirm the second order.
3. Load billing from `/cashier/billing`.
Expected: Second order joins the same open DiningTransaction and the combined bill includes both orders.

### UAT-CASH-008
Actor: Cashier
Route: `/cashier/billing`
Steps:
1. Enter the UAT DiningTransaction identifier in the existing billing screen.
2. Load the bill.
3. Compare names, historical prices, subtotals, total, and status.
4. If safe, change the current MenuItem price through Admin and reload the bill.
Expected: Bill displays historical order values; changing current menu price does not alter the existing order value.

### UAT-CASH-009
Actor: Cashier
Route: `/cashier/billing`
Steps:
1. Load an unpaid UAT bill.
2. Choose Record Cash.
3. Enter an amount below the remaining balance.
4. Submit.
Expected: Paid amount and remaining balance update correctly; transaction is not paid; no Receipt is created yet.

### UAT-CASH-010
Actor: Cashier
Routes: `/cashier/billing`, `/cashier/receipt`
Steps:
1. Record a partial Cash payment.
2. Record GCash for the remaining amount with a UAT reference.
3. Load the resulting Receipt.
Expected: Both payments are represented; balance reaches zero; transaction becomes paid; exactly one Receipt exists.

### UAT-CASH-011
Actor: Cashier
Route: `/cashier/billing`
Steps:
1. Load an unpaid bill.
2. Choose Record Cash.
3. Enter cash received greater than the outstanding balance.
Expected: Applied amount is limited to the outstanding amount and backend-calculated change is displayed correctly.

### UAT-CASH-012
Actor: Cashier
Route: `/cashier/billing`
Steps:
1. Load an unpaid UAT bill.
2. Choose Record GCash.
3. Enter a valid UAT reference.
4. Submit.
Expected: Manual GCash settlement succeeds; reference is retained; no automatic GCash verification claim is displayed.

### UAT-CASH-013
Actor: Cashier
Route: `/cashier/receipt`
Steps:
1. Enter the Receipt identifier in the existing Receipt screen.
2. Load the Receipt.
3. Inspect all visible fields.
Expected: Receipt number, transaction/table, item snapshot, prices, subtotals, total, payment breakdown, Cash details, and GCash reference are shown where applicable. No BIR, VAT, or official tax receipt claim is made.

### UAT-CASH-014
Actor: Cashier
Route: `/cashier/receipt`
Steps:
1. Load a Receipt.
2. Trigger Print receipt.
3. Inspect browser print preview.
4. Cancel or complete preview without requiring a physical printer.
Expected: Clean printable view; same Receipt is reused; backend print tracking increments correctly if visible through the application. Physical/silent printing remains unverified.

### UAT-CASH-015
Actor: Customer and Cashier
Routes: customer `/order/{qrToken}`; Cashier `/cashier/billing`
Steps:
1. Complete payment for the first DiningTransaction.
2. Keep the TableSession active.
3. Submit and confirm another customer order.
4. Load billing for the new order.
Expected: New order receives a new DiningTransaction; previous paid transaction remains unchanged.

## Kitchen status workflow audit

Actor: Cashier/Kitchen
Route: `/cashier`

Inspect whether the current frontend exposes preparing/completed or equivalent controls. If no such controls exist, record the browser workflow as BLOCKED or requirement gap. Do not treat backend-only endpoints as interactive browser verification.

## 13D-5 Admin UAT

### UAT-ADMIN-001
Actor: Admin
Route: `/admin`
Steps: Log in as Admin and open the dashboard.
Expected: Dashboard loads without frontend/runtime errors and displays backend-provided metrics.

### UAT-ADMIN-002
Actor: Admin
Route: `/admin/tables`
Steps: Open Tables & QR; inspect list and open the UAT table QR display; perform only supported create/edit actions using UAT data.
Expected: Table list and QR display work; permanent QR token remains stable; no production QR labels are regenerated.

### UAT-ADMIN-003
Actor: Admin
Route: `/admin/menu` (category controls, if exposed in the current page)
Steps: Inspect category list and perform supported create/update/active actions using UAT Category.
Expected: Category operations persist and active/inactive behavior is correct. If category CRUD controls are not exposed, record that UI gap.

### UAT-ADMIN-004
Actor: Admin
Route: `/admin/menu`
Steps: Create/inspect a UAT Menu Item with category, name, description, and price; verify its availability and inventory foundation.
Expected: Menu item fields persist; category is correct; creation creates expected Inventory foundation; availability behavior is visible and correct.

### UAT-ADMIN-005
Actor: Admin
Route: `/admin/inventory`
Steps: Select a UAT inventory item; use Restock; enter a positive quantity and reason where prompted.
Expected: Quantity increases; movement is recorded; availability synchronizes according to the business rule.

### UAT-ADMIN-006
Actor: Admin
Route: `/admin/inventory`
Steps: Select Adjust; enter a target physical quantity and reason; submit; repeat with target zero only on a disposable UAT item.
Expected: Quantity becomes the target; movement delta is correct; zero quantity marks item unavailable; operation is Admin-only.

### UAT-ADMIN-007
Actor: Admin
Route: `/admin/inventory`
Steps: Inspect whether movement history is exposed by the page.
Expected: If exposed, UAT movements are understandable and visible. If not exposed, record BLOCKED — UI not exposed; do not infer browser support.

### UAT-ADMIN-008
Actor: Admin
Route: `/admin/reports`
Steps: Open each available report selection: sales, transactions, payments, menu-item sales, inventory, and inventory movements. Exercise date filters, pagination, populated state, and empty state where controls exist.
Expected: Reports load without errors and show correct data/empty states; filters and pagination work where exposed.

### UAT-ADMIN-009
Actor: Admin
Route: `/admin/users`
Steps: Open Users and inspect the staff table.
Expected: Staff accounts load; name, email, role, status, and actions are shown; passwords, hashes, remember tokens, and secrets are absent.

### UAT-ADMIN-010
Actor: Admin
Route: `/admin/users`
Steps: Create a temporary UAT Cashier using a unique email and a locally chosen password; log out and test that account at `/cashier`.
Expected: Account is created; Cashier can log in; Cashier does not see Admin navigation.

### UAT-ADMIN-011
Actor: Admin
Route: `/admin/users`
Steps: Edit the UAT Cashier name, email, or role; save; reload the page.
Expected: Safe field changes persist; omitted password remains unchanged.

### UAT-ADMIN-012
Actor: Admin and Cashier
Routes: `/admin/users`, `/cashier`
Steps: Keep a Cashier session active; deactivate that account from Admin Users; make a protected request from the existing Cashier session.
Expected: Future login is blocked and existing session access is blocked with safe handling.

### UAT-ADMIN-013
Actor: Admin and Cashier
Routes: `/admin/users`, `/cashier`
Steps: Reactivate the Cashier; log in again.
Expected: Cashier login and Cashier access are restored.

### UAT-ADMIN-014
Actor: Admin
Route: `/admin/users`
Steps: With only one active Admin remaining, attempt to deactivate or demote that Admin.
Expected: Action is rejected clearly; the last active Admin remains active and is not demoted.

## 13D-6 Chatbot UAT

### UAT-CHAT-001
Actor: Customer
Route: `/order/{qrToken}`
Steps: Open the customer page; use the Menu assistant; submit a normal question while no provider key is configured.
Expected: Friendly service-unavailable fallback is displayed; browsing and ordering remain usable; no stack trace appears.

### UAT-CHAT-002
Actor: Customer
Route: `/order/{qrToken}`
Steps: Only if an approved provider key is configured, ask “What drinks are available?” and then an unrelated programming question.
Expected: Response is restaurant-scoped and grounded; unrelated request is refused safely; no secret/internal data is exposed. If no approved key exists, mark BLOCKED — optional provider not configured.

## 13D-7 Negative/failure UAT

### UAT-FAIL-001
Actor: Customer or Staff
Route: Any active screen, preferably `/order/{qrToken}` or `/admin/users`
Steps: Temporarily use the browser’s network controls or disconnect only the test browser; trigger a reload/request; restore connectivity.
Expected: User-friendly error or retry state; no blank screen or React crash.

### UAT-FAIL-002
Actor: Admin
Route: `/admin/users`
Steps: Submit an invalid staff form: weak password, mismatched confirmation, duplicate email, or invalid required field.
Expected: Validation/business error is shown; no partial account is created.

### UAT-FAIL-003
Actor: Cashier
Routes: `/admin/users`, `/admin/reports`
Steps: While logged in as Cashier, navigate directly and inspect responses.
Expected: Admin UI/API access is blocked; no Admin data is exposed.

### UAT-FAIL-004
Actor: Customer and Cashier
Routes: `/order/{qrToken}`, `/admin/inventory`, `/cashier`
Steps: Submit an order for an available UAT item; before confirmation, set its inventory to zero through Admin; attempt Cashier confirmation.
Expected: Confirmation fails safely; no negative inventory, KitchenTicket, or invalid billing state is created.

### UAT-FAIL-005
Actor: Cashier
Routes: `/cashier/billing`, `/cashier/receipt`
Steps: Submit the same payment action twice or retry after a successful response if the UI permits.
Expected: No incorrect over-settlement and no duplicate Receipt.

### UAT-FAIL-006
Actor: Customer
Route: `/order/{qrToken}`
Steps: Use the chatbot while its provider is unavailable or unconfigured.
Expected: Safe fallback appears; customer menu/order functions remain usable.

## Physical and deployment-only cases

Record separately as `BLOCKED — ENVIRONMENT REQUIRED` unless genuinely tested:

- Physical phone QR scan.
- Final LAN hostname/IP.
- Final production QR labels.
- Physical or thermal printer output.
- Silent/automatic printer dispatch.
- Production-server image deployment.

## Cleanup

1. Record post-UAT counts.
2. Remove only temporary UAT-prefixed tables, categories, menu items, orders, transactions, payments, receipts, and tickets where safe.
3. Do not delete unrelated development/reference data.
4. Keep temporary staff accounts only if intentionally needed for later testing; otherwise deactivate or remove them through an approved process.
5. Verify cleanup and record the result.
6. Restore the unrelated LGU Nginx service if it was stopped for UAT.

## Result summary

Total planned cases: 54
Passed:
Failed:
Blocked:
Not run:

Authentication:
Customer:
Cashier/Kitchen/Billing/Payment:
Admin:
Chatbot:
Negative/Failure:

Readiness decision: UAT BLOCKED until manual results are supplied and reviewed.
Client acceptance: not claimed.
Production deployment: not started.


## Focused defect remediation — Cashier post-login redirect

UAT-AUTH-003 was reported as a frontend redirect defect, not a backend authorization defect. `frontend/src/App.jsx` now returns the authenticated user from `login()` and navigates Admin users to `/admin` and Cashiers to `/cashier` after successful login. Existing role guards and backend authorization were not weakened or changed.

Technical verification: frontend lint passed with 0 warnings/errors; frontend build passed. Manual browser retest is still required. UAT-AUTH-003 remains pending and must not be changed to PASS until local browser execution confirms the Cashier lands directly on `/cashier`.
