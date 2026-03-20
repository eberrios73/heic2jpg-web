# heic2jpg-web

There are dozens of online HEIC converters. All of them require uploading your company's photos to someone else's server. This one runs on your network, behind your firewall, and takes 60 seconds to deploy.

![Terminal-style UI with live conversion progress](https://img.shields.io/badge/stack-Apache%20%2B%20PHP%20%2B%20libheif-blue)

Built with Cursor.

Author: Enrique Berrios

## The Problem

iPhones shoot HEIC by default. Windows can't open them without a codec from the Microsoft Store ($0.99/device, needs Microsoft accounts, doesn't deploy well via GPO/Intune). When you've got 200+ machines across multiple offices, that's not a real solution.

And those free online converters? Great — until your users are uploading client photos, legal documents, medical images, or anything covered by an NDA. "We sent your files to a random website" is not a sentence you want in an incident report.

## The Solution

A self-service web page on any Linux box you already have. Users drag-and-drop their HEIC files (or a ZIP), get back a ZIP of JPGs. No installs on their machines. No tickets. No data leaves your network. Takes 60 seconds to deploy.

## Quick Start

```bash
git clone https://github.com/eberrios73/heic2jpg-web.git
cd heic2jpg-web
sudo bash install.sh
```

That's it. The installer:

1. Installs Apache, PHP, ImageMagick, libheif, and ffmpeg
2. Upgrades libheif to 1.18+ (fixes iPhone HEIC compatibility)
3. Fixes ImageMagick's security policy to allow HEIC
4. Configures PHP upload limits (500MB)
5. Deploys the web files to `/var/www/html/heic2jpg/`
6. Restarts Apache

Your converter will be live at `http://YOUR_SERVER_IP/heic2jpg/`

## Features

- Drag-and-drop or click to upload
- Accepts individual `.heic`/`.heif` files or `.zip` archives
- Terminal-style live progress log
- Automatically skips macOS `._` resource forks and `__MACOSX` directories
- Falls back through 3 converters: heif-convert → ffmpeg → ImageMagick
- Returns all converted JPGs in a single ZIP download
- No client-side installs required

## What Gets Installed

| Package | Purpose |
|---------|---------|
| `apache2` | Web server |
| `php` + `php-zip` | Backend processing |
| `libheif-examples` | Primary converter (`heif-convert`) |
| `imagemagick` | Fallback converter (`convert`) |
| `ffmpeg` | Second fallback converter |

## Gotchas the Installer Handles For You

### libheif version

Ubuntu 22.04/24.04 ship libheif 1.17.x which fails on modern iPhone photos with HDR gain maps:

```
Invalid input: Too many auxiliary image references
```

The installer automatically upgrades to 1.18+ from the [strukturag PPA](https://launchpad.net/~strukturag/+archive/ubuntu/libheif).

### ImageMagick security policy

ImageMagick blocks HEIC by default. The policy at `/etc/ImageMagick-6/policy.xml` only allows `{GIF,JPEG,PNG,WEBP}`. The installer adds `HEIC,HEIF` to the allow list.

### macOS ZIP metadata

When Mac users create ZIP files, they include `._` resource fork files and a `__MACOSX/` directory. These aren't real images and will cause conversion errors. The converter automatically filters them out.

## Manual Install

If you prefer to do it yourself:

```bash
# Install packages
sudo apt install apache2 php libapache2-mod-php php-zip imagemagick libheif-examples ffmpeg

# Upgrade libheif
sudo add-apt-repository ppa:strukturag/libheif
sudo apt update
sudo apt install --only-upgrade libheif1 libheif-examples

# Fix ImageMagick policy
sudo sed -i 's/pattern="{GIF,JPEG,PNG,WEBP}"/pattern="{GIF,JPEG,PNG,WEBP,HEIC,HEIF}"/' /etc/ImageMagick-6/policy.xml

# Increase PHP upload limits (edit the apache2 php.ini)
# upload_max_filesize = 500M
# post_max_size = 500M
# max_execution_time = 300

# Deploy
sudo mkdir -p /var/www/html/heic2jpg
sudo cp index.html heic_convert.php heic_download.php /var/www/html/heic2jpg/
sudo chown -R www-data:www-data /var/www/html/heic2jpg
sudo systemctl restart apache2
```

## Publishing to GitHub

From the project directory (with [GitHub CLI](https://cli.github.com/) installed and logged in):

```bash
git init
git add .
git commit -m "Initial commit: self-hosted HEIC to JPG converter"
gh repo create heic2jpg-web --public --source=. --remote=origin --push
```

Or create an empty public repository on GitHub, then:

```bash
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/eberrios73/heic2jpg-web.git
git branch -M main
git push -u origin main
```

## File Structure

```
heic2jpg-web/
├── install.sh          # One-command installer
├── index.html          # Standalone frontend (no frameworks, no dependencies)
├── heic_convert.php    # Backend: handles upload, conversion, streams progress
├── heic_download.php   # Backend: serves the converted ZIP
├── LICENSE             # MIT
└── README.md
```

## Requirements

- Ubuntu 20.04, 22.04, or 24.04 (Debian should also work)
- Root access for installation
- ~200MB disk space for packages
- `apache2` + PHP (used to serve `index.html` and run `heic_convert.php`/`heic_download.php`)
- `libheif-examples`, `ffmpeg`, and ImageMagick (converter backends used as fallbacks)

## License

MIT
