# Laravel TUI

TUI for Laravel. 

## Requirements

- PHP `^8.4.1`
- Laravel 12 or 13
- Composer
- Nerd Font for icons (configured in terminal)

### Package dependencies

| Dependency           | Version            |
|----------------------|--------------------|
| `illuminate/support` | `^12.0 \|\| ^13.0` |
| `symfony/process`    | `^7.4 \|\| ^8.0`   |
| `symfony/tui`        | `^8.1.10`          |

### Optional tools

Some modules use tools and services from your application:

| Feature                 | Requirements                              |
|-------------------------|-------------------------------------------|
| Git                     | Git available on `PATH`                   |
| Database and migrations | A configured db connection                |
| Queues                  | A configured Laravel queue connection     |
| Vite process            | Node.js, npm                              |
| Checks                  | The executables used by configured checks |

## Installation

Install the package in Laravel application:

```bash
composer require --dev johannesclimacus/laravel-tui
```

Open TUI:

```bash
php artisan ui
```

### Publish configuration

```bash
php artisan vendor:publish --tag=laravel-tui-config
```

### Publish art and colours

```bash
php artisan vendor:publish --tag=laravel-tui-art
```

## Modules

| Module     | Features                                                                              |
|------------|---------------------------------------------------------------------------------------|
| Overview   | App information                                                                       |
| Artisan    | Search, execute commands and browse output                                            |
| Logs       | Browse log files                                                                      |
| Scheduler  | Inspect scheduled tasks                                                               |
| Queue      | Inspect queues and failed jobs, retry and remove failed jobs                          |
| Migrations | Inspect migration status, run migrations and seeders, perform rollbacks               |
| Database   | Browse connections, tables, columns, indexes, and paginated rows                      |
| Routes     | Search routes, inspect methods, paths, actions and middleware                         |
| Checks     | Run linters, tests, and custom checks                                                 |
| Processes  | Interact with development processes with live output                                  |
| Git        | View branch information, changed files, staged and unstaged diffs, and recent commits |

Module order is controlled by the `modules` array in`config/laravel-tui.php`.

## Navigation

| Key                     | Action                                   |
|-------------------------|------------------------------------------|
| `↑` / `↓`               | Navigate lists or text                   |
| `Enter`                 | Open the selected item or perform action |
| `Tab` / `Shift+Tab`     | Move focus forward or backward           |
| `Escape`                | Return from details or leave search      |
| `/`                     | Open search                              |
| `Page Up` / `Page Down` | Scroll output                            |
| `q`                     | Quit                                     |
| `a`                     | Change art                               |

`Ctrl+C` interrupts an active operation where supported. Otherwise, it exits the dashboard.

Module-specific shortcuts include:

| Module    | Key | Action                       |
|-----------|-----|------------------------------|
| Git       | `r` | Refresh                      |
| Checks    | `r` | Rerun the selected check     |
| Processes | `r` | Restart the selected process |
| Processes | `s` | Stop the selected process    |
| Logs      | `f` | Change severity filter       |
| Logs      | `p` | Pause or resume updates      |

## Art and colour editor

```bash
php artisan serve
```

```text
http://127.0.0.1:8000/laravel-tui/art-editor
```

The editor is available when `APP_ENV=local` and `laravel-tui.editor.enabled` is enabled.

Colour changes are saved to your application's configured art directory.

### Custom art

Place UTF-8 Braille text art in:

```text
resources/laravel-tui/art/my-art.txt
```

Select art by its filename:

```php
'art' => [
    'path' => resource_path('laravel-tui'),
    'default' => 'my-art',
    'modules' => [
        'overview' => 'my-art',
        'artisan' => 'miku.exe',
    ],
],
```

## Custom modules

Implement the `Module` interface and append the class to the `modules` array in `config/laravel-tui.php`. 
