# Janet's Quality Catering – Smart Event and Transaction Management System

Web system for Janet's Quality Catering Services: event booking wizard, payments with proof of payment,
client records, food menus, client surveys, reports, accounts, and Predictive Analytics using the ARIMA model.

Built with PHP 8, Bootstrap 5 and Chart.js. The same code runs on two databases:

| Where | Database | Files and sign-in sessions |
|---|---|---|
| XAMPP on a computer | MySQL / MariaDB | `uploads/` folder and PHP session files |
| Online (Vercel) | PostgreSQL on Supabase | stored in the database (Vercel's disk is temporary) |

## Run on your computer (XAMPP)

1. Copy the project to `C:\xampp\htdocs\VERANO` and start Apache and MySQL.
2. Create the database `janets_catering` and import `database/janets_catering_schema.sql`,
   then `database/psgc_address_tables.sql` and `database/migration_seed_food_menus.sql`.
3. Open `http://localhost/VERANO/login.php`.

## Put it online: Supabase (database) + Vercel (hosting)

### 1. Supabase database
1. Create a project at https://supabase.com (region: Southeast Asia (Singapore)). Keep the database password.
2. Open **SQL Editor**, paste the whole file `database/postgres/janets_catering_pg.sql`, and click **Run**.
3. Copy the data from XAMPP. On the XAMPP computer, in PowerShell inside the project folder, with the
   **Session pooler** details from Supabase (**Connect** button):
   ```powershell
   $env:TARGET_HOST = 'aws-0-ap-southeast-1.pooler.supabase.com'
   $env:TARGET_PORT = '5432'
   $env:TARGET_DB   = 'postgres'
   $env:TARGET_USER = 'postgres.your-project-ref'
   $env:TARGET_PASS = 'your-database-password'
   C:\xampp\php\php.exe -d extension=pdo_pgsql cli\copy_to_pg.php
   ```
   This copies every table and the uploaded receipts and photos.

### 2. Vercel hosting
1. Import this GitHub repository at https://vercel.com/new (Framework preset: **Other**, no build command).
2. In **Settings → Environment Variables** add:

   | Name | Value |
   |---|---|
   | `DB_DRIVER` | `pgsql` |
   | `DB_HOST` | Session pooler host, e.g. `aws-0-ap-southeast-1.pooler.supabase.com` |
   | `DB_PORT` | `5432` |
   | `DB_NAME` | `postgres` |
   | `DB_USER` | `postgres.your-project-ref` |
   | `DB_PASS` | the Supabase database password |
   | `DB_SSL` | `1` |
   | `SMTP_PASSWORD` | the Gmail App Password used for login codes |
   | `APP_BASE_URL` | the site address, e.g. `https://janets-catering.vercel.app` |

3. Deploy (or Redeploy after adding the variables).

`vercel.json` runs every page through `api/index.php` with the community runtime `vercel-php`.

### Online differences
- Backup & Restore: Supabase keeps the database backups (Supabase dashboard → Database → Backups).
- The 30-day archive clean-up runs during normal use, at most once an hour, instead of a Windows scheduled task.
- Changing the database later: update XAMPP first, then regenerate the PostgreSQL schema with
  `C:\xampp\php\php.exe cli\make_pg_schema.php`.

## Passwords
Passwords are saved as salted SHA-256 hashes (PBKDF2-HMAC-SHA256, 310,000 rounds), in the form
`pbkdf2_sha256$rounds$salt$hash`. People type their normal password; the database never holds it.
Accounts with an older bcrypt hash are moved to SHA-256 automatically the next time they sign in.

## Postal codes
In Event Bookings the postal code is filled in from the chosen City/Municipality and Barangay
(`includes/ph_postal_codes.php`). It can still be typed over. ZIP data: [GeoNames](https://www.geonames.org),
licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
