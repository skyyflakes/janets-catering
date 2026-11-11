# Janet's Quality Catering – Smart Event and Transaction Management System

Web system for Janet's Quality Catering Services: event booking wizard, payments with proof of payment,
client records, food menus, client surveys, reports, accounts, and Predictive Analytics using the ARIMA model.

Built with PHP 8, MariaDB/MySQL, Bootstrap 5 and Chart.js.

## Run on your computer (XAMPP)

1. Copy the project to `C:\xampp\htdocs\VERANO` and start Apache and MySQL.
2. Create the database `janets_catering` and import `database/janets_catering_schema.sql`,
   then `database/psgc_address_tables.sql` and `database/migration_seed_food_menus.sql`.
3. Open `http://localhost/VERANO/login.php`.

## Deploy on Vercel

`vercel.json` runs the pages with the community runtime `vercel-php`. Every request goes through `api/index.php`.

1. Import this repository at https://vercel.com/new (Framework preset: Other). No build command is needed.
2. The system needs an online MySQL database. Create one, import the SQL files above, then add these
   environment variables in Vercel (Settings → Environment Variables): `DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USER`, `DB_PASS`, and `DB_SSL=1` if the provider requires an encrypted connection.
3. Redeploy.

Limitations on Vercel: uploaded receipts and photos are not kept, sign-ins can be lost between requests,
emailed login codes need the mail settings file, and scheduled backups and clean-up do not run.
