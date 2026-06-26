# Design Scope: 360 Panorama Gallery Website

## 1. Project Overview

Build a simple PHP-based website for hosting and viewing 360-degree panorama images. The public-facing site will present a clean thumbnail gallery. When a user clicks a thumbnail, the selected panorama opens in a lightbox-style viewer where the user can pan and zoom around the 360 image.

The visual design should be minimal, dark, and focused on the images, using OLED-safe black as the primary background colour.

---

## 2. Public Website

### 2.1 Homepage

The homepage will be the default public view of the website.

It should:

* Display only panorama thumbnails.
* Use a black / OLED-safe black background.
* Present thumbnails in a clean responsive grid.
* Allow users to click a thumbnail to open the panorama.
* Avoid unnecessary text, navigation, or visual clutter.

Each thumbnail should be generated from the default starting view of the panorama where possible.

### 2.2 Panorama Lightbox

When a thumbnail is clicked, the panorama should open in a full-screen or near full-screen lightbox.

The lightbox should:

* Display the selected 360 panorama.
* Allow the user to pan around the image.
* Allow zooming in and out.
* Use a black background.
* Include a clear “X” close button in the top-right corner.
* Return the user to the thumbnail gallery when closed.
* Work on desktop, tablet, and mobile devices.

The lightbox should not navigate away from the homepage unless technically required.

---

## 3. Admin Interface

### 3.1 Admin URL

The admin interface will be located at:

`/panoadmin`

This section will not be visible from the public homepage.

### 3.2 Admin Features

The admin interface should allow an authorised user to:

* Upload new panorama images.
* View existing uploaded panoramas.
* Reorder panoramas using a simple drag-and-drop or move up / move down interface.
* Delete panoramas if required.
* Optionally rename or label panoramas for admin clarity.
* Save the display order used on the public homepage.

### 3.3 Upload Requirements

The upload system should:

* Accept 360 panorama image files.
* Store uploaded images on the server.
* Generate or capture a thumbnail from the default view.
* Validate file type and size.
* Prevent unsafe file uploads.
* Provide basic upload success and error messages.

---

## 4. Design and Theme

The website should use a dark, image-first visual style.

### 4.1 Colour Direction

Primary background:

`#000000`

Supporting colours:

* Dark grey for admin panels and borders.
* White or light grey text where needed.
* Minimal accent colours only for buttons, warnings, or upload status.

### 4.2 Public Gallery Style

The public gallery should feel like a simple visual browser:

* Black page background.
* Even thumbnail spacing.
* No heavy borders.
* Smooth hover effect on thumbnails.
* Responsive layout for different screen sizes.

### 4.3 Lightbox Style

The lightbox should:

* Use a black overlay.
* Keep the panorama as the focus.
* Place the close “X” button in the top-right corner.
* Use a large, touch-friendly close button.
* Avoid bright interface elements unless required.

---

## 5. Technical Scope

The site will be built using PHP with standard HTML, CSS, and JavaScript.

Suggested structure:

* PHP for upload handling, gallery rendering, and admin functions.
* JavaScript panorama viewer for pan and zoom interaction.
* JSON file or lightweight database for storing panorama order and metadata.
* Server-side image validation for uploads.
* Thumbnail generation process during upload.

A lightweight approach is preferred unless future requirements justify a full database or CMS.

---

## 6. Data and File Storage

Each panorama should have stored metadata, including:

* File name.
* Thumbnail path.
* Display order.
* Upload date.
* Optional title or label.
* Default view settings if supported by the panorama viewer.

Uploaded files should be stored in a dedicated folder, for example:

`/uploads/panoramas/`

Generated thumbnails could be stored in:

`/uploads/thumbnails/`

Metadata could be stored in either:

`/data/panoramas.json`

or a small database table.

---

## 7. Security Requirements

The admin interface should be protected.

Minimum security requirements:

* Admin login or password protection for `/panoadmin`.
* File type validation.
* File size limits.
* Prevention of executable file uploads.
* Sanitised file names.
* CSRF protection for upload, delete, and reorder actions.
* Admin-only access to upload and management functions.

---

## 8. Responsive Behaviour

The public gallery and panorama viewer should work across:

* Desktop browsers.
* Tablets.
* Mobile phones.

The thumbnail grid should automatically adjust based on screen width.

The panorama lightbox controls, especially the close button, should be usable on touch devices.

---

## 9. Out of Scope

The initial version will not include:

* User accounts for public visitors.
* Public comments.
* Ratings or likes.
* Search or filtering.
* Payment features.
* Multi-user admin roles.
* Cloud storage integration.
* Complex CMS functionality.
* Virtual tour linking between panoramas.

---

## 10. Success Criteria

The project will be considered successful when:

* The homepage displays uploaded panorama thumbnails only.
* Clicking a thumbnail opens the selected panorama in a lightbox.
* The panorama can be panned and zoomed.
* The lightbox includes a top-right “X” close button.
* Closing the lightbox returns to the thumbnail gallery.
* `/panoadmin` allows panorama upload and ordering.
* The site uses a black OLED-friendly theme.
* The design works well on desktop and mobile.
