# Astro SMB Full

A full-featured Astro static site template for small businesses with products, reviews, inventory, and multi-tab forms.

**Based on real production sites.** This isn't a starter template—it's the exact architecture powering live businesses.

## Features

- **Static Site Generation** - Fast, secure, SEO-friendly
- **Tailwind CSS v4** - Modern utility-first styling
- **Config-Driven** - Single `config.yaml` for all settings
- **Product Catalog** - Product pages with pricing and schema markup
- **Review System** - Display Google reviews with AI-powered page matching
- **Blog System** - Markdown-based with content collections
- **Contact Forms** - PHP backend with email notifications via Resend
- **Quote System** - Request quotes for products/services
- **Admin Panel** - View submissions, manage reviews, tag reviews with AI
- **Rich Snippets** - Product, Article, LocalBusiness, Breadcrumb schemas
- **Mobile Responsive** - Works on all devices
- **Lighthouse 100s** - No bloat, no hydration, minimal JS

## Quick Start

```bash
# Install dependencies
npm install

# Start development server
npm run dev

# Build for production
npm run build

# Preview production build
npm run preview
```

## Configuration

All site settings are in `config.yaml`:

```yaml
site:
  name: "Your Company"
  tagline: "Your Tagline"
  description: "Your description"
  url: "https://yoursite.com"

contact:
  email: "hello@yoursite.com"
  phone: "1-800-555-0100"
  address:
    street: "123 Main Street"
    city: "Anytown"
    # ...

products:
  currency: "USD"
  items:
    - size: "Small"
      slug: "small-package"
      name: "Small Package"
      new: { min: 99, max: 149 }
      used: null

navigation:
  main:
    - name: "Home"
      href: "/"
    # ...

reviews:
  pages:
    - path: "/"
      layout: "featured"
      max_reviews: 3
```

### Environment-Specific Settings

Create `config.local.yaml` for secrets (not committed to git):

```yaml
email:
  resend_api_key: "re_your_api_key_here"

admin:
  secret_path: "your-secret-admin-path"
```

## Project Structure

```
├── src/
│   ├── pages/           # Routes (index, about, contact, blog, products, etc.)
│   ├── components/      # Reusable Astro components
│   ├── layouts/         # Page templates
│   ├── content/         # Markdown content (blog posts)
│   ├── config/          # Config loader (site.ts)
│   └── styles/          # Global CSS
├── api/                 # PHP backend
│   ├── contact.php      # Contact form handler
│   ├── quote.php        # Quote request handler
│   └── admin.php        # Admin panel
├── data/                # JSON data files
│   ├── reviews.json     # Google reviews
│   ├── inventory.json   # Product inventory (optional)
│   └── submissions.json # Form submissions
├── public/              # Static assets
├── config.yaml          # Site configuration
└── config.local.yaml    # Local overrides (create this)
```

## Key Features

### Products & Pricing

Define products in `config.yaml` with price ranges. Prices display on product pages and generate schema markup for Google rich results:

```yaml
products:
  currency: "USD"
  items:
    - size: "Premium"
      slug: "premium-package"
      name: "Premium Package"
      description: "Our top-tier offering"
      new: { min: 799, max: 999 }
      used: { min: 599, max: 699 }
```

### Review Widget

Display reviews on any page with multiple layouts:

- `featured` - Large cards with prominent stars (Home, About)
- `standard` - Medium cards with navigation arrows
- `compact` - Minimal inline style (Product pages)
- `3-row` - Three cards side by side

Configure per-page in `config.yaml`, then use the admin panel to tag reviews to pages.

### Admin Panel

Access at `/api/admin.php?key=YOUR_SECRET_PATH`

- View and export form submissions
- View quote requests
- Manage reviews from Google Business
- AI-powered review tagging to match reviews with relevant pages

### Schema Markup

Built-in JSON-LD schemas for:

- **Product** - With pricing, availability, aggregateRating
- **LocalBusiness** - With geo coordinates, hours, contact
- **Article** - For blog posts
- **BreadcrumbList** - Auto-generated from URL path

## Deployment

### Static Hosting (Vercel, Netlify)

```bash
npm run build
# Upload the `dist/` folder
```

### Self-Hosted (with PHP)

```bash
npm run build
```

Then upload `dist/` and `api/` to your server. Configure your web server to:
- Serve `dist/` as the document root
- Route `/api/*.php` to the `api/` directory

### Automated Deployment

Use the built-in deploy scripts:

```bash
npm run deploy:staging   # Deploy to staging server
npm run deploy:prod      # Deploy to production
```

Configure server details in `scripts/deploy.js`.

## Stack

| Layer | Choice |
|-------|--------|
| Framework | Astro 5 |
| Styling | Tailwind CSS 4 |
| Types | TypeScript |
| Backend | PHP 8 |
| Email | Resend API |

## Philosophy

Most small business sites are over-engineered. This one is not.

- Static HTML with PHP endpoints
- No client-side framework
- No hydration
- No JavaScript bundles (except ~2KB for mobile nav)
- No database (JSON files are fine at this scale)
- No build pipeline complexity

The entire site rebuilds in under 3 seconds. Lighthouse scores are 100 across the board because there's nothing to optimize away.

## License

MIT License - see [LICENSE](LICENSE)
