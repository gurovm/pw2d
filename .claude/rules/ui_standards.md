---
description: Enforces UI and Responsive design standards.
globs: "**/*.blade.php, **/*.css, **/*.js"
---
# UI & Frontend Rules (Strict Compliance Required)

Whenever you generate or modify Blade templates, UI components, or Frontend logic, you MUST adhere to the following principles:


## 1. Mobile-First & Responsive
- **Responsive Design:** All interfaces must be fully responsive and usable on mobile devices.
- **Mobile First:** Design for mobile screens first (default Tailwind classes), then use responsive breakpoints (e.g., `md:`, `lg:`) to enhance the layout for larger screens.
- **Usability:** Ensure tables are scrollable on mobile (`overflow-x-auto`) and buttons/touch-targets are appropriately sized.
