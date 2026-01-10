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

## Prerequisites

Before you begin, make sure you have:

- **Node.js 18+** - [Download here](https://nodejs.org/)
- **npm** (comes with Node.js)
- **PHP 8+** (only needed for forms/admin on your server)

To check your versions:
```bash
node --version   # Should show v18.x.x or higher
npm --version    # Should show 9.x.x or higher
```

## Quick Start

### 1. Clone and Install

```bash
# Clone the repository
git clone https://github.com/stooky/astro-smb-full.git my-site
cd my-site

# Install dependencies
npm install
```

### 2. Configure Your Site

Open `config.yaml` and update with your business info:

```yaml
site:
  name: "Your Company Name"
  tagline: "Your Tagline Here"
  description: "A brief description for SEO"
  url: "https://yoursite.com"

contact:
  email: "hello@yoursite.com"
  phone: "1-800-555-0100"
  address:
    street: "123 Main Street"
    city: "Your City"
    state: "ST"
    zip: "12345"
    country: "USA"

products:
  currency: "USD"
  items:
    - size: "Basic"
      slug: "basic"
      name: "Basic Package"
      description: "Perfect for getting started"
      new: { min: 99, max: 149 }
      used: null
```

### 3. Start Development

```bash
npm run dev
```

Open http://localhost:4321 in your browser. Changes auto-refresh.

### 4. Build for Production

```bash
npm run build
```

Your site is now in the `dist/` folder, ready to deploy.

## Configuration

### Main Config (`config.yaml`)

This is the single source of truth for your entire site:

| Section | What It Controls |
|---------|------------------|
| `site` | Name, tagline, description, URL |
| `contact` | Email, phone, address |
| `hours` | Business hours |
| `social` | Social media links (leave empty to hide) |
| `products` | Product catalog with pricing |
| `navigation.main` | Header navigation links |
| `navigation.footer` | Footer link columns |
| `reviews.pages` | Which pages show reviews and how |
| `analytics` | Google Analytics/Tag Manager IDs |

### Secrets (`config.local.yaml`)

Create this file for sensitive settings (it's git-ignored):

```yaml
email:
  resend_api_key: "re_your_api_key_here"
  from_email: "Your Company <hello@yourdomain.com>"

admin:
  secret_path: "your-secret-admin-path"
```

## Project Structure

```
my-site/
├── src/
│   ├── pages/                  # Each file = one page
│   │   ├── index.astro         # Homepage
│   │   ├── about.astro         # About page
│   │   ├── contact.astro       # Contact form
│   │   ├── products/           # Product pages
│   │   └── blog/
│   │       ├── index.astro     # Blog listing
│   │       └── [...slug].astro # Individual posts
│   ├── components/
│   │   ├── Hero.astro          # Hero sections
│   │   ├── Section.astro       # Content sections
│   │   ├── ReviewWidget.astro  # Google reviews display
│   │   ├── TabbedContactForm.astro  # Multi-tab forms
│   │   └── schema/             # JSON-LD components
│   ├── layouts/Layout.astro    # Main page template
│   ├── content/blog/           # Blog posts (markdown)
│   ├── config/site.ts          # Loads config.yaml
│   └── styles/global.css       # Colors and global styles
├── api/                        # PHP backend
│   ├── contact.php             # Contact form handler
│   ├── quote.php               # Quote request handler
│   ├── admin.php               # Admin panel
│   └── tag-reviews.php         # AI review tagging
├── data/
│   ├── reviews.json            # Google reviews
│   ├── inventory.json          # Product inventory
│   ├── submissions.json        # Contact form logs
│   └── quote-requests.json     # Quote request logs
├── scripts/                    # Utility scripts
│   ├── deploy.js               # Deployment automation
│   └── tag-reviews.js          # AI review tagging
├── public/                     # Static files
├── config.yaml                 # Site settings
└── config.local.yaml           # Secrets (create this)
```

## Key Features

### Products & Pricing

Define products in `config.yaml`:

```yaml
products:
  currency: "USD"
  items:
    - size: "Small"
      slug: "small"
      name: "Small Package"
      description: "Perfect for individuals"
      new: { min: 99, max: 149 }
      used: null
    - size: "Large"
      slug: "large"
      name: "Large Package"
      description: "For growing businesses"
      new: { min: 499, max: 599 }
      used: { min: 299, max: 399 }
```

Prices automatically:
- Display on product pages
- Generate Google Product schema for rich results
- Show price ranges (min-max)

Set `null` for unavailable options (e.g., no used products).

### Google Reviews

Display real Google reviews on your pages:

1. **Add reviews to `data/reviews.json`:**

```json
{
  "totalCount": 50,
  "averageRating": 4.9,
  "reviews": [
    {
      "id": "review_001",
      "author": "John Smith",
      "rating": 5,
      "date": "2024-01-15",
      "text": "Great service! Highly recommend.",
      "hasPhoto": false
    }
  ]
}
```

2. **Configure which pages show reviews in `config.yaml`:**

```yaml
reviews:
  pages:
    - path: "/"
      layout: "featured"      # Large cards
      position: "before-cta"
      max_reviews: 3
    - path: "/products"
      layout: "3-row"         # Three side-by-side
      position: "after-hero"
      max_reviews: 6
```

3. **Tag reviews to pages** using the admin panel's AI tagging feature.

**Review Layouts:**
| Layout | Best For |
|--------|----------|
| `featured` | Homepage, About - large prominent cards |
| `standard` | Service pages - medium cards with arrows |
| `compact` | Product pages - minimal inline style |
| `3-row` | Landing pages - three cards side by side |

### Quote System

The inventory checker lets customers request quotes:

1. Products defined in `data/inventory.json`
2. Customers browse and click "Get Quote"
3. Quote requests logged to `data/quote-requests.json`
4. You receive email with full details

Prices in inventory are hidden from customers—you control markup.

### Admin Panel

Access at: `https://yoursite.com/api/admin.php?key=YOUR_SECRET_PATH`

**Tabs:**
- **Submissions** - View/export contact form entries
- **Quotes** - View quote requests with item details
- **Reviews** - Manage Google reviews
- **Tag Reviews** - AI-powered review-to-page matching

### Schema Markup (Rich Snippets)

Built-in JSON-LD for better Google results:

| Schema | Auto-generated From |
|--------|---------------------|
| `Product` | config.yaml products + reviews.json ratings |
| `LocalBusiness` | config.yaml contact info |
| `Article` | Blog post frontmatter |
| `BreadcrumbList` | URL path |

Test your schema: [Google Rich Results Test](https://search.google.com/test/rich-results)

## Customization

### Changing Colors

Edit `src/styles/global.css`:

```css
:root {
  /* Primary - your main brand color */
  --color-primary-500: #2563eb;
  --color-primary-600: #1d4ed8;
  --color-primary-700: #1e40af;

  /* Secondary - accent color */
  --color-secondary-500: #0891b2;
  --color-secondary-600: #0e7490;
}
```

**Tip:** Use [Tailwind Color Generator](https://uicolors.app/create) to create a full palette.

### Adding a Product Page

1. Create `src/pages/products/your-product.astro`
2. Use existing product pages as templates
3. Add to navigation in `config.yaml`
4. Add product to `products.items` in config

### Adding Blog Posts

Create markdown in `src/content/blog/`:

```markdown
---
title: "Your Post Title"
description: "SEO description"
pubDate: 2024-01-15
author: "Your Name"
image: "/images/blog/your-image.jpg"
imageAlt: "Image description"
tags: ["tips", "guide"]
draft: false
---

Your content here...
```

### Adding Images

- **General images:** `public/images/`
- **Blog images:** `public/images/blog/`
- **Product images:** `public/images/products/`

Reference with `/images/your-image.jpg`.

## Contact Form Setup

### Resend API (Recommended)

1. Sign up at [resend.com](https://resend.com) (free: 3,000 emails/month)
2. Create API key
3. Verify your domain
4. Add to `config.local.yaml`:

```yaml
email:
  resend_api_key: "re_your_key_here"
  from_email: "Your Company <hello@yourdomain.com>"

contact_form:
  recipient_email: "you@yourcompany.com"
  subject_prefix: "[Website]"
```

### Form Security

Built-in protections:
- **Honeypot field** - Catches spam bots
- **Rate limiting** - 10 per IP per hour
- **Server-side validation** - Never trusts client

## Deployment

### Option 1: Automated Deploy (Recommended)

Configure server details in `scripts/deploy.js`, then:

```bash
npm run deploy:staging   # Test first
npm run deploy:prod      # Go live
```

This SSHs into your server, pulls changes, and rebuilds.

### Option 2: Manual Deploy

1. Build:
```bash
npm run build
```

2. Upload to server:
   - `dist/` → web root
   - `api/` → accessible via `/api/`
   - `config.yaml` → server root (not web-accessible)
   - `data/` → server root (not web-accessible)

### Option 3: Static Hosting (Vercel/Netlify)

Works for the static site, but:
- No PHP = no contact form (use Formspree)
- No admin panel
- No quote system

## Troubleshooting

### "npm install" fails
- Ensure Node.js 18+ installed
- Delete `node_modules` and `package-lock.json`, retry

### Reviews not showing
- Check `data/reviews.json` has reviews
- Verify reviews are tagged to the page path
- Check `config.yaml` has the page in `reviews.pages`

### Product schema warnings
- Ensure product slugs in pages match `config.yaml`
- Check `products.items` has the correct slugs

### Contact form not sending
- Verify `config.local.yaml` exists
- Check Resend API key and domain verification
- Look at PHP error logs on server

### Build fails
- Read the error message carefully
- Common: missing image, broken import, YAML syntax error

## Philosophy

Most small business sites are over-engineered. This one is not.

- Static HTML with PHP endpoints
- No client-side framework, no hydration
- No JavaScript bundles (except ~2KB for nav)
- No database (JSON files work at this scale)
- No build pipeline complexity

The entire site rebuilds in under 3 seconds. Lighthouse 100s because there's nothing to optimize away.

## Stack

| Layer | Technology |
|-------|------------|
| Framework | Astro 5 |
| Styling | Tailwind CSS 4 |
| Types | TypeScript |
| Backend | PHP 8 |
| Email | Resend API |

## Getting Help

- **Astro Docs:** https://docs.astro.build
- **Tailwind Docs:** https://tailwindcss.com/docs
- **Issues:** https://github.com/stooky/astro-smb-full/issues

## License

MIT License - use it for anything, free or commercial.
