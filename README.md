# MediChat Demo

Small Laravel monolith demo for a medical exam chat assistant.

## Stack

- Laravel
- Vue 3
- Tailwind CSS
- MySQL
- Vite

## Local Setup

After dependencies are installed by you:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run dev
```

Laragon can serve the app at:

```text
http://medichat.test
```

The database is configured in `.env` as `medichat_db` with user `root` and no password.

## Current Scope

This first version only contains the project base:

- Laravel app skeleton
- Vue and Tailwind entrypoints
- Home view with a Vue "Hello world"
- Eloquent models and migrations for patients, conversations, messages, and blood exams

Chat, AI integration, file upload parsing, and websocket behavior will be added in the next functionality phase.
