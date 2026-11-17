# Campus Lost & Found

A mobile-first web application that helps students and campus staff report, search, claim, and verify lost and found belongings.

**Live Demo:** [Your New Live InfinityFree URL]

## Key Features
* **Student & Staff Accounts:** Role-based login and instant student registration.
* **Lost & Found Board:** Real-time search and status filtering (*Active, Lost, Found, Returned/Claimed*).
* **Client-Side Image Compression:** Automatically resizes uploaded photos in the browser for fast loading and reliable storage.
* **Hidden Verification Clues:** Reporters can attach private item details visible only to staff during claim verification.
* **Staff Verification Queue:** Admin dashboard to approve or reject claims, prevent duplicate claim spam, update case statuses, remove posts, and clear review logs.

## Tech Stack
* **Frontend:** HTML5, CSS3 (Flexbox & Grid), Vanilla JavaScript
* **Backend:** PHP 8 (PDO)
* **Database:** MySQL
* **Hosting:** InfinityFree

## Setup Instructions
1. Clone or download this repository into your web server's `htdocs/` directory.
2. Rename `config.example.php` to `config.php` and enter your MySQL host, database name, username, and password.
3. Open `index.html` in your browser—the database tables and default staff account are created automatically on first load.

## Demo Admin Credentials
* **Staff Email:** `staff@campus.edu`
* **Staff Password:** `admin123`

## Group Members
* **[Your Name]** – Role
* **[Member 2 Name]** – Role
* **[Member 3 Name]** – Role
