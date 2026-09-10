# Admin Frontend Structure Gap Analysis and Completion

Date: 2026-09-09
Status: Frontend structure implemented; formal UAT paused

## Design references inspected

All ten reference files in `Sources/architecture docs/Structure Design/Admin` were opened, and all ten embedded PNG screenshots in the vault `+/` attachments directory were opened through the host-connected browser:

1. `1 admin dashboard.md` / dashboard screenshot
2. `2 admin user.md` / users screenshot
3. `3 Admin table & qr.md` / tables and QR screenshot
4. `4 Admin categories.md` / categories screenshot
5. `5 Admin menu.md` / menu screenshot
6. `6 Admin inventory.md` / inventory screenshot
7. `7 admin orders.md` / orders screenshot
8. `8 admin transaction.md` / transaction screenshot
9. `9 admin reports.md` / reports screenshot
10. `10 Admin system.md` / system screenshot

The references define an Admin application shell with persistent navigation and separate operational surfaces for dashboard, users, tables/QR, categories, menu, inventory, orders, transactions, reports, and system/settings. The current implementation uses the existing Pass-over coffee/cream visual language rather than introducing a separate UI framework or replacing the frontend.

## Gap table

| Design page | Current route | Backend support | Result |
|---|---|---|---|
| Dashboard | `/admin` | `GET /api/dashboard` | Existing route retained; uses real metrics. |
| Users | `/admin/users` | `/api/admin/users` endpoints | Existing route retained; staff management remains Admin-only. |
| Tables & QR | `/admin/tables` | Restaurant table CRUD and QR endpoint | Existing route retained; no production QR work added. |
| Categories | `/admin/categories` | Category CRUD API | Added dedicated page with list/create/edit/activate/deactivate/delete. |
| Menu Items | `/admin/menu` | Menu item CRUD API | Existing route retained; navigation label separated from Categories. |
| Inventory | `/admin/inventory` | Inventory/restock/adjust APIs | Existing route retained; no backend changes. |
| Orders | None | Cashier queue/detail/confirm/reject only; no Admin read-only order API | Intentionally omitted. Adding it would require a backend endpoint and role decision. |
| Transactions | `/admin/transactions` | Admin-protected `/api/reports/transactions` | Added read-only page with date/status/search filters. |
| Reports | `/admin/reports` | Reporting APIs | Existing route retained; selector-based report surface remains. |
| System/Settings | None | No settings model/API | Intentionally omitted; no dead placeholder added. |

## Sidebar result

Admin navigation now contains:

- Dashboard
- Tables & QR
- Menu Items
- Categories
- Inventory
- Transactions
- Reports
- Users

Cashier navigation remains limited to Cashier Queue, Billing & Payments, and Receipts. Admin has no order confirmation/rejection, KitchenTicket, Cash, or GCash action controls.

## Backend endpoints used

Categories page:

- `GET /api/categories`
- `POST /api/categories`
- `PUT /api/categories/{category}`
- `DELETE /api/categories/{category}`

Transactions page:

- `GET /api/reports/transactions`
- Query filters: `from`, `to`, `status`, `search`
- Response fields consumed: `transaction_number`, `status`, `table.table_number`, `total_amount`, `paid_amount`, `remaining_balance`, and `opened_at`

No fake data, hard-coded metrics, new backend APIs, migrations, or schema changes were added.

## Intentional gaps and conflicts

- The Orders reference cannot be implemented safely as an Admin page with current APIs. Cashier order endpoints are operational and role-protected; they must not be duplicated into Admin.
- The System reference is not implemented because no settings contract exists.
- Inventory movement history is not a separate frontend page; the existing Reports selector provides the supported movement report.
- Existing pages remain lightweight and may not reproduce every visual detail in the screenshots. The task implemented structural completeness supported by real APIs, not pixel-perfect cloning.

## Verification

- Frontend `npm run lint`: passed, 0 warnings and 0 errors.
- Frontend `npm run build`: passed.
- Backend files changed: none.
- Backend baseline preserved: 157 passed, 0 failed, 808 assertions.
- Manual browser route verification was not performed in this environment because formal UAT is paused and the browser automation policy cannot navigate the private/local application gateway.
