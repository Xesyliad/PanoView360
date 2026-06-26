# PanoView360

PanoView360 is a lightweight PHP application for hosting and viewing 360-degree panorama images. It provides a public thumbnail gallery, a full-screen panorama lightbox, and a protected admin area for uploads and ordering.

Repository: https://github.com/Xesyliad/PanoView360

## Download

You can obtain the project in one of two ways:

1. Clone the repository:

```bash
git clone https://github.com/Xesyliad/PanoView360
```

2. Download the repository as a ZIP archive from your source control provider and extract it to your web server document root or application directory.

## Install

1. Ensure your server has a recent PHP runtime installed.
2. Make sure the required PHP extensions are available, especially:
   - `gd`
   - `exif`
3. Copy `example.config.php` to `config.php`.
4. Update `config.php` with your production settings, especially the admin password hash.
5. Create or verify the writable application directories:
   - `data/`
   - `data/sessions/`
   - `uploads/`
   - `uploads/panoramas/`
   - `uploads/thumbnails/`
6. Point your web server at the project root so `index.php` is the public entry point.
7. Visit the site in a browser and sign in at `/panoadmin/`.

## Permissions

The application needs normal read/write access to its own storage directories. In practice, the web server user should be able to:

- create and update files in `data/`
- create session files in `data/sessions/`
- create, update, and delete files in `uploads/panoramas/`
- create, update, and delete files in `uploads/thumbnails/`

If the directories do not exist yet, the application will try to create them, so the process also needs permission to create those folders.

## Configuration

The main configuration file is `config.php`.

Use `example.config.php` as the template for local or new installations. The example file contains the same structure as the live config, but with secrets removed.

## Panorama Image Requirements

For best results, upload true equirectangular panorama images.

The application can accept common image formats, but a file will only behave like a 360 panorama in the viewer if it is actually a panorama source image. Recommended characteristics:

- Aspect ratio of roughly `2:1`
- Example dimensions such as `8192 x 4096`, `6000 x 3000`, or similar
- Full horizontal wrap-around content
- No heavy cropping that removes the left/right seam

Additional metadata is helpful:

- GPS EXIF allows the admin map preview to show a location
- Heading / yaw metadata may improve future directional features

If the file is just a normal photo, the viewer can still display it as an image, but it will not behave like a true 360 panorama.

## Third-Party Software

This project bundles third-party browser libraries in `assets/vendor/`:

- Leaflet
- Pannellum

If you redistribute the repository or package the application, keep the upstream license notices for those bundled files in place. The repository root also includes an MIT `LICENSE` for the project code itself.

The map preview uses OpenStreetMap tiles in the browser, so public deployments should retain appropriate attribution for map data and tiles.

## Notes

- The admin area is available at `/panoadmin/`.
- Uploaded panoramas are stored locally on the server.
- Panorama ordering is persisted in the application data store.
