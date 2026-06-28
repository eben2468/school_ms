# Deployment Guide — School Management System (cPanel / shared hosting)

This guide takes the app from local XAMPP to a live cPanel host at your **domain
root** (`https://yourdomain.com/`). It was written after preparing the codebase
for hosting: all internal links were converted from `/school_ms/...` to root
(`/...`), security headers/HTTPS redirect were added, and the databases were
exported to `deploy/database/`.

---

## 0. What was already prepared for you

- **Links fixed for the domain root.** ~80 files had hardcoded `/school_ms/`
  URLs; these are now root-relative (`/...`). Do **not** put the app inside a
  `school_ms/` subfolder anymore — it must live at the web root.
- **`.htaccess`** — forces HTTPS, blocks dev/maintenance scripts, `scratch/`,
  `maintenance/`, `config/secrets.php`, dotfiles, and raw `.sql/.log/.md` files;
  sets security headers.
- **`.user.ini`** — production PHP settings: errors logged not shown, hardened
  session cookies, sane upload/execution limits.
- **`config/secrets.php`** mechanism — DB credentials live in an untracked,
  web-blocked file. Template: `deploy/secrets.production.php`.
- **Database dumps** — `deploy/database/*.sql` (central + 4 tenant schools).

---

## 1. Prerequisites on the host

- PHP **7.4+** (8.1+ recommended) with PDO_MySQL, mysqli, OpenSSL, mbstring, GD.
- MySQL / MariaDB.
- An SSL certificate (cPanel → *SSL/TLS Status* → run AutoSSL; it's free).

---

## 2. Upload the application files

Upload **the contents** of the project into `public_html/` (the web root) so that
`index.php` sits directly in `public_html/`.

**Do NOT upload these** (developer-only / local data):

| Skip | Why |
|------|-----|
| `deploy/` | Contains DB dumps + secrets template — keep off the live server (or delete after use). |
| `scratch/` | One-off dev scripts. |
| `.git/` | Version-control internals. |
| `*.sql`, `*.err` | Database dumps. |
| `backups/`, `uploads/` (optional) | Re-create on host; upload `uploads/` only if you want to carry existing files/logos. |
| `reset_sidebar.html`, `test_full_width.html`, loose `test_*`/`fix_*`/`setup_*`/`migrate_*` scripts | Dev utilities. |

> Tip: zip the project locally **excluding** the folders above, upload the zip via
> cPanel File Manager, and *Extract* it into `public_html/`.

---

## 3. Create the databases & user (cPanel → MySQL Databases)

cPanel **prefixes every database and user with your account name**
(e.g. account `myacct` → database `myacct_school_ms`). Note your real prefix.

1. Create the central DB: `school_ms`  → real name `myacct_school_ms`.
2. Create one DB per existing school (4 tenants):
   - `school_ms_tenant_dreams`
   - `school_ms_tenant_cambridge`
   - `school_ms_tenant_eben`
   - `school_ms_tenant_cynthia`
   (real names get the `myacct_` prefix automatically.)
3. Create **one MySQL user** (e.g. `myacct_schoolms`) with a strong password.
4. **Add that user to every database above** with **ALL PRIVILEGES**.

---

## 4. Import the data (cPanel → phpMyAdmin)

The dumps in `deploy/database/` each begin with `CREATE DATABASE ... / USE ...`
for the **local** names. On cPanel you import into the **prefixed** DB you made:

For each `.sql` file:
1. In phpMyAdmin, select the matching prefixed database on the left.
2. Open the **Import** tab and upload the file.
3. If import complains about the `CREATE DATABASE`/`USE` lines, open the `.sql`
   in a text editor and delete the top lines:
   `CREATE DATABASE ...;` and `USE \`...\`;` — then import into the selected DB.

Import all five: `school_ms.sql` (central) + the four tenant files.

---

## 5. Point the tenants at the prefixed database names

The central DB stores each school's database name in `schools.db_name`. Those
values are still the local names, so update them to the prefixed names.
In phpMyAdmin, select the **central** DB (`myacct_school_ms`) → SQL tab, and run
(replace `myacct_` with your real prefix):

```sql
UPDATE schools SET db_name = CONCAT('myacct_', db_name)
WHERE db_name LIKE 'school_ms_tenant_%';
```

Run it **once**. Verify:

```sql
SELECT id, name, db_name FROM schools;
```

---

## 6. Configure credentials

1. Copy `deploy/secrets.production.php` to `config/secrets.php` on the host.
2. Edit it with your real values:
   - `DB_HOST` → usually `localhost`
   - `DB_NAME` → `myacct_school_ms`
   - `DB_USER` / `DB_PASS` → the user from step 3
   - `DB_TENANT_PREFIX` → `myacct_school_ms_tenant_`
   - `APP_DEBUG` → `false`

---

## 7. SSL / HTTPS

`.htaccess` redirects all HTTP to HTTPS. Enable AutoSSL **before** visiting the
site, or temporarily comment the three `RewriteRule ^ https://...` lines until
the certificate is active (otherwise you'll get a redirect loop).

After confirming HTTPS works site-wide, optionally enable HSTS by uncommenting
the `Strict-Transport-Security` line in `.htaccess`.

If your host runs **mod_php** (not PHP-FPM/CGI), `.user.ini` is ignored — copy
its directives into *MultiPHP INI Editor* or `php.ini` instead.

---

## 8. First login & smoke test

1. Visit `https://yourdomain.com/` — you should reach the login page.
2. Log in as your super admin / school admin.
3. Check: dashboard loads, sidebar links work, logo shows, a tenant school's data
   appears, and creating a record works (verifies DB writes + CSRF).
4. Confirm `https://yourdomain.com/config/secrets.php` returns **403/Forbidden**.
5. Confirm `https://yourdomain.com/scratch/` returns **403**.

---

## 9. ⚠️ Multi-tenant onboarding on shared hosting (important)

Adding a **new** school (Super Admin → Add School) runs `CREATE DATABASE` at
runtime. On most **shared cPanel** plans the MySQL user is **not allowed** to
create databases, so self-service onboarding of new schools will fail there.
Your four existing schools work fine (their DBs are imported in step 4).

To add new schools you have options:
- **Recommended:** host on a **VPS / cloud server** where the DB user has full
  privileges (then onboarding "just works").
- **Shared host workaround:** pre-create the tenant DB in cPanel manually using
  the exact name `<prefix><school-code>` (matching `DB_TENANT_PREFIX` + the code
  you'll type), grant your user access, *then* run Add School — the
  `CREATE DATABASE IF NOT EXISTS` becomes a no-op and the rest proceeds.
- Ask your host to grant the `CREATE` privilege to your MySQL user.

---

## 10. Maintenance scripts

`maintenance/` and other setup scripts are blocked from the web by `.htaccess`.
If you ever need `maintenance/fix_tenant_schemas.php` (tenant schema self-heal),
run it from the cPanel **Terminal**/SSH or a one-off cron command:

```
php /home/myacct/public_html/maintenance/fix_tenant_schemas.php
```

(Tenant schemas also self-heal automatically on normal use.)

---

## Quick checklist

- [ ] Files uploaded to `public_html/` root (index.php at root)
- [ ] `deploy/`, `scratch/`, `.git/`, dumps **not** uploaded
- [ ] 5 databases created (account-prefixed) + user with ALL PRIVILEGES on each
- [ ] All 5 `.sql` files imported
- [ ] `schools.db_name` updated to prefixed names (step 5)
- [ ] `config/secrets.php` created & filled (incl. `DB_TENANT_PREFIX`)
- [ ] AutoSSL active; site loads over HTTPS
- [ ] `config/secrets.php` and `scratch/` return 403
- [ ] Logged in and verified a tenant school's data + a write action
