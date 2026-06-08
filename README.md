# MediChat Demo

Laravel monolith demo for a medical exam chat assistant. The app uses Vue, Tailwind, Element Plus, MySQL, Laravel Reverb websockets, and OpenAI.

## Requirements

- Windows 10
- Laragon 8
- PHP 8.3+
- Composer 2.8+
- Node 22+
- npm 10+
- MySQL through Laragon

Make sure your terminal is using Laragon's PHP 8.3 binary:

```powershell
php -v
```

If it shows an older PHP version, temporarily prepend the PHP 8.3 path:

```powershell
$env:Path = "C:\laragon-8\bin\php\php-8.3.16-Win32-vs16-x64;" + $env:Path
```

## Database

Create a MySQL database in Laragon:

```text
medichat_db
```

Default local credentials:

```text
DB_DATABASE=medichat_db
DB_USERNAME=root
DB_PASSWORD=
```

## Environment

Copy `.env.example` to `.env` if `.env` does not exist:

```powershell
copy .env.example .env
```

Set your OpenAI key:

```text
OPENAI_API_KEY=your_key_here
OPENAI_MODEL=gpt-4o-mini
```

The app is configured for Laragon virtual host:

```text
APP_URL=http://medichat.test
REVERB_HOST=medichat.test
```

## Install

From the project folder:

```powershell
composer install
npm install
php artisan key:generate
php artisan migrate:fresh
```

If dependencies were already installed before new packages were added, run:

```powershell
composer update openai-php/client laravel/reverb --with-dependencies
npm install
```

## Run Locally

Laragon should serve the backend at:

```text
http://medichat.test
```

Start Vite:

```powershell
npm run dev
```

Start Laravel Reverb in another terminal:

```powershell
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Then open:

```text
http://medichat.test
```

## PDF Uploads

Only PDF files are supported for now, up to 50MB. The app sends PDFs to OpenAI for extraction but does not store the original file.

If large uploads fail, adjust PHP settings in Laragon:

```ini
upload_max_filesize=50M
post_max_size=55M
```

Restart Laragon after changing PHP settings.

## Useful Commands

Reset all database tables:

```powershell
php artisan migrate:fresh
```

Clear one patient's conversations while keeping the patient and AI audit history:

```powershell
php artisan medichat:clear-conversation IDENTIFICATION_NUMBER
```

Clear cached config after changing `.env`:

```powershell
php artisan config:clear
```

Build frontend assets:

```powershell
npm run build
```
