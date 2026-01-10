/**
 * Site Configuration Loader
 * Reads from config.yaml - the single source of truth for all settings
 */

import fs from 'node:fs';
import path from 'node:path';
import YAML from 'yaml';

// Type definitions
interface NavItem {
  name: string;
  href: string;
  children?: NavItem[];
}

interface FooterLinks {
  services: NavItem[];
  products: NavItem[];
  company: NavItem[];
  legal: NavItem[];
}

interface Address {
  street: string;
  city: string;
  state: string;
  zip: string;
  country: string;
}

interface Contact {
  email: string;
  phone: string;
  phoneLocal: string;
  address: Address;
}

interface Social {
  facebook: string;
  instagram: string;
  twitter: string;
  linkedin: string;
  youtube: string;
  whatsapp: string;
  googleBusiness: string;
}

interface PriceRange {
  min: number;
  max: number;
}

export interface ContainerProduct {
  size: string;
  slug: string;
  name: string;
  description: string;
  new: PriceRange | null;
  used: PriceRange | null;
}

interface Products {
  currency: string;
  containers: ContainerProduct[];
}

interface Hours {
  monday: string;
  tuesday: string;
  wednesday: string;
  thursday: string;
  friday: string;
  saturday: string;
  sunday: string;
}

interface Analytics {
  googleAnalytics: {
    enabled: boolean;
    measurementId: string;
  };
  googleTagManager: {
    enabled: boolean;
    containerId: string;
  };
}

interface ReviewPageConfig {
  path: string;
  layout: 'featured' | 'standard' | 'compact' | '3-row';
  position: 'before-cta' | 'after-cta' | 'after-hero';
  maxReviews: number;
}

interface ReviewsConfig {
  pages: ReviewPageConfig[];
  tagged: Record<string, string[]>;
}

export interface Review {
  id: string;
  author: string;
  rating: number;
  date: string;
  text: string;
  hasPhoto: boolean;
}

export interface SiteConfig {
  name: string;
  tagline: string;
  description: string;
  url: string;
  contact: Contact;
  social: Social;
  hours: Hours;
  navigation: NavItem[];
  footerLinks: FooterLinks;
  defaultOgImage: string;
  analytics: Analytics;
  reviews: ReviewsConfig;
  products: Products;
  copyright: string;
}

// Load and parse config.yaml
function loadConfig(): SiteConfig {
  const configPath = path.join(process.cwd(), 'config.yaml');
  const configFile = fs.readFileSync(configPath, 'utf-8');
  const config = YAML.parse(configFile);

  // Transform YAML structure to match expected interface
  return {
    // Site identity
    name: config.site.name,
    tagline: config.site.tagline,
    description: config.site.description,
    url: config.site.url,
    defaultOgImage: config.site.default_og_image,

    // Contact (transform snake_case to camelCase)
    contact: {
      email: config.contact.email,
      phone: config.contact.phone,
      phoneLocal: config.contact.phone_local,
      address: {
        street: config.contact.address.street,
        city: config.contact.address.city,
        state: config.contact.address.state,
        zip: config.contact.address.zip,
        country: config.contact.address.country,
      },
    },

    // Social media
    social: {
      facebook: config.social.facebook || '',
      instagram: config.social.instagram || '',
      twitter: config.social.twitter || '',
      linkedin: config.social.linkedin || '',
      youtube: config.social.youtube || '',
      whatsapp: config.social.whatsapp || '',
      googleBusiness: config.social.google_business || '',
    },

    // Business hours
    hours: config.hours,

    // Navigation
    navigation: config.navigation.main,

    // Footer links
    footerLinks: {
      services: config.navigation.footer.services,
      products: config.navigation.footer.products,
      company: config.navigation.footer.company,
      legal: config.navigation.footer.legal,
    },

    // Analytics
    analytics: {
      googleAnalytics: {
        enabled: config.analytics?.google_analytics?.enabled || false,
        measurementId: config.analytics?.google_analytics?.measurement_id || '',
      },
      googleTagManager: {
        enabled: config.analytics?.google_tag_manager?.enabled || false,
        containerId: config.analytics?.google_tag_manager?.container_id || '',
      },
    },

    // Reviews config
    reviews: {
      pages: (config.reviews?.pages || []).map((p: any) => ({
        path: p.path,
        layout: p.layout || 'standard',
        position: p.position || 'before-cta',
        maxReviews: p.max_reviews || 3,
      })),
      tagged: config.reviews?.tagged || {},
    },

    // Products config (supports both 'containers' and 'items' field names)
    products: {
      currency: config.products?.currency || 'USD',
      containers: (config.products?.containers || config.products?.items || []).map((c: any) => ({
        size: c.size,
        slug: c.slug,
        name: c.name,
        description: c.description,
        new: c.new ? { min: c.new.min, max: c.new.max } : null,
        used: c.used ? { min: c.used.min, max: c.used.max } : null,
      })),
    },

    // Generated
    copyright: `© ${new Date().getFullYear()} ${config.site.name}. All rights reserved.`,
  };
}

// Get container product by slug
export function getContainerBySlug(slug: string): ContainerProduct | undefined {
  return siteConfig.products.containers.find(c => c.slug === slug);
}

// Load reviews from JSON file
function loadReviews(): Review[] {
  try {
    const reviewsPath = path.join(process.cwd(), 'data', 'reviews.json');
    const reviewsFile = fs.readFileSync(reviewsPath, 'utf-8');
    const data = JSON.parse(reviewsFile);
    return (data.reviews || []).map((r: any) => ({
      id: r.id,
      author: r.author,
      rating: r.rating,
      date: r.date,
      text: r.text,
      hasPhoto: r.hasPhoto || false,
    }));
  } catch {
    return [];
  }
}

// Get reviews for a specific page path
export function getReviewsForPage(pagePath: string): {
  reviews: Review[];
  config: ReviewPageConfig | null;
} {
  const pageConfig = siteConfig.reviews.pages.find(p => p.path === pagePath);
  if (!pageConfig) {
    return { reviews: [], config: null };
  }

  const allReviews = loadReviews();
  const tagged = siteConfig.reviews.tagged;

  // Find reviews tagged for this page
  const taggedReviewIds = Object.entries(tagged)
    .filter(([_, pages]) => pages.includes(pagePath))
    .map(([id]) => id);

  const pageReviews = allReviews
    .filter(r => taggedReviewIds.includes(r.id))
    .slice(0, pageConfig.maxReviews);

  return { reviews: pageReviews, config: pageConfig };
}

// Export the loaded config
export const siteConfig = loadConfig();
