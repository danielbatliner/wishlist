# Wishlist

A simple personal wishlist web app built with PHP and SQLite. No external dependencies required.

## Features

- Add wishes with a name, links, notes, and a photo
- Upload a photo via file picker or paste one from the clipboard
- URLs in notes get converted to links. 
- Delete wishes
- All data stored locally in a SQLite database

## Requirements

- PHP 8.1 or later with the SQLite3 extension enabled

## Setup & Start

```bash
php -S 127.0.0.1:8080
```

Open [http://127.0.0.1:8080](http://127.0.0.1:8080) in your browser.

## Data

All data (database and uploaded images) is stored in the `data/` directory, which is created automatically on first run.
