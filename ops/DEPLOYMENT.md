# Pass-over Cafe RMS Deployment Checklist

This document describes a production-style LAN deployment. It does not select the restaurant server address or contain real credentials.

## 1. Prepare the server

1. Install Docker Engine and the Docker Compose plugin on the dedicated Ubuntu/Linux server.
2. Copy the project to the server.
3. Keep the server on the restaurant LAN and configure a DHCP reservation or static address.
4. Allow only required LAN web access through the host firewall. Do not expose MySQL to the LAN.

## 2. Create deployment-only configuration

Create a root Compose environment file from `ops/compose.prod.env.example`. Set unique values for:

- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_APP_PASSWORD`

Create `backend/.env` from `backend/.env.production.example`. Set:

- `APP_KEY` using `php artisan key:generate` on the fresh deployment environment.
- `APP_URL` to the approved server URL.
- `FRONTEND_URL` to the approved customer-reachable server URL.
- `DB_PASSWORD` equal to `MYSQL_APP_PASSWORD`.
- `SANCTUM_STATEFUL_DOMAINS` to the approved staff/browser host.
- unique staff passwords.
- `AI_API_KEY` only if the optional chatbot demonstration requires it.

Production requirements:

```text
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
DB_HOST=mysql
DB_CONNECTION=mysql
SESSION_DRIVER=database
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

For plain HTTP LAN deployment, keep `SESSION_SECURE_COOKIE=false`. If HTTPS is later enabled, set `SESSION_SECURE_COOKIE=true`.

Never commit real `.env` files, application keys, database passwords, staff passwords, or AI keys.

## 3. Validate before starting

```text
docker compose --env-file ops/compose.prod.env -f docker-compose.yml -f docker-compose.prod.yml config
```

The merged production configuration must show:

- only Nginx published to the host;
- no MySQL host port;
- Nginx on the selected web port;
- production backend and frontend Dockerfiles;
- health-based app/frontend dependencies.

## 4. Build and start

```text
docker compose --env-file ops/compose.prod.env -f docker-compose.yml -f docker-compose.prod.yml build
docker compose --env-file ops/compose.prod.env -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Do not use `docker compose down -v`; it removes the named MySQL volume.

## 5. Initialize Laravel

Run controlled deployment commands inside the backend container:

```text
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run migrations only after confirming the target environment and taking a backup.

## 6. Provision the first Admin

Do not ship default credentials. Use the interactive command:

```text
php artisan staff:create-admin
```

It prompts for name, email, and password. The password must contain at least 8 characters. The command rejects duplicate email addresses and creates an active Admin account.

The current project has no user-management API/UI yet; subsequent Cashier provisioning remains a separate required scope item.

## 7. Verify services

```text
docker compose --env-file ops/compose.prod.env -f docker-compose.yml -f docker-compose.prod.yml ps
docker compose --env-file ops/compose.prod.env -f docker-compose.yml -f docker-compose.prod.yml logs --no-color --tail=100 nginx app frontend mysql
```

Confirm all health checks are healthy before testing staff/customer flows.

## 8. QR deployment procedure

1. Select and approve the final LAN address.
2. Set `FRONTEND_URL` to that address.
3. Request fresh QR SVGs from the Admin table screen/API.
4. Print and replace all old QR labels.
5. Test `/order/{qrToken}` from an actual customer phone on restaurant Wi-Fi.

Changing `FRONTEND_URL` does not change permanent table tokens, but old printed QR codes continue to contain the old address.

## 9. Backup and restore operations

Backup before migrations and at least daily during operation:

```text
MYSQL_ROOT_PASSWORD=<secret> MYSQL_DATABASE=passover bash ops/backup_database.sh backups/passover-YYYY-MM-DD_HHMMSS.sql
```

Test restores only into an isolated target:

```text
MYSQL_ROOT_PASSWORD=<secret> RESTORE_TARGET_DATABASE=passover_restore_YYYYMMDD ALLOW_DESTRUCTIVE_RESTORE=YES bash ops/restore_database.sh backups/passover-YYYY-MM-DD_HHMMSS.sql
```

Retain at least seven daily and four weekly backups outside the primary database container.

## 10. Final acceptance

Before claiming readiness, complete formal UAT for customer ordering/status, Admin, Cashier, payments, receipts, reports, browser printing, LAN access, and AI fallback. Confirm the actual printer and customer phone with the client.

## Current limitations

- Final LAN hostname/IP is not selected.
- HTTPS is not configured. HTTP-only LAN deployment exposes credentials/session traffic to LAN observers.
- MySQL host-port removal is defined only in the production override; development Compose still publishes port 3306.
- Admin user-management API/UI is not implemented.
- Silent physical printing is not implemented.
- AI provider configuration is optional.
