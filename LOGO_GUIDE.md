# Logo Management Guide

## Overview
Your website now features a professional brand identity system with multiple logo variations for different uses. The logo represents Amber Fabrics' commitment to sustainable craftsmanship and premium quality textiles.

## 🎨 Available Logo Versions

### 1. Professional Handloom Icon (Currently Active)
**File:** `images/logo.svg` (and `logo-icon.svg`)  
- Clean handloom weave pattern in decorative frame
- Gold corner accents for premium feel
- Perfect for website header alongside brand name
- Works beautifully on dark navbar

### 2. Full Brand Logo - Light Version (For Dark Backgrounds)
**File:** `images/logo-brand-light.svg`  
- Complete brand identity with icon + "Amber Fabrics" text
- Includes tagline: "SUSTAINABLE CRAFTSMANSHIP"
- White/teal color scheme optimized for dark backgrounds
- Ideal for presentations, social media, headers

### 3. Full Brand Logo - Dark Version (For Light Backgrounds)
**File:** `images/logo-brand.svg`  
- Same layout as light version
- Deep teal-green and gold color scheme
- Perfect for business cards, letterheads, and documents
- Print-ready for fabric labels and packaging

## 🎯 Design Philosophy

The Amber Fabrics logo incorporates:
- **Handloom frame:** Traditional textile manufacturing heritage
- **Warp & weft threads:** Craftsmanship and weaving expertise
- **Gold accents:** Premium quality and attention to detail
- **Teal-green colors:** Eco-friendly and sustainable values
- **Elegant typography:** Trust and professionalism

## Features Added

### 1. **Logo Display**
- Logo appears in the navigation bar next to the site name
- Displays on both public pages and admin pages
- Automatically loads from site settings
- Falls back gracefully if no logo is set

### 2. **Logo Upload via Admin Panel**
Navigate to: **Admin Dashboard → Site Settings**

**Upload Requirements:**
- Supported formats: JPG, PNG, SVG, WEBP
- Maximum file size: 2MB
- Recommended dimensions: 120x120px (or similar square/rectangular)
- Recommended: SVG or PNG with transparent background

### 3. **Default Logo**
A default SVG logo has been created at:
- `images/logo.svg`

This logo features:
- Teal circular background matching your brand color (#0f766e)
- Textile weave pattern
- Letter "V" for Amber Fabrics
- Professional, scalable design

## How to Change the Logo

### Method 1: Via Admin Settings (Recommended)
1. Log into the admin panel
2. Navigate to **Dashboard → Site Settings**
3. Scroll to "Branding Logo" section
4. Click "Choose File"
5. Select your logo image (JPG, PNG, SVG, or WEBP)
6. Click "Save Settings"

### Method 2: Manual File Replacement
1. Place your logo file in `images/` directory
2. Name it `logo.svg` (or `logo.png`, `logo.jpg`, etc.)
3. Update `config/site-settings.json`:
   ```json
   {
     "branding_logo": "images/logo.svg"
   }
   ```

## Logo Design Tips

### For Best Results:
1. **Use SVG format** - Scales perfectly at any size, small file size
2. **Transparent background** - Looks professional on dark navbar
3. **Square or horizontal aspect ratio** - Works best in header layout
4. **Simple design** - More recognizable at small sizes
5. **High contrast** - Ensure visibility on dark background

### Recommended Tools:
- **Free SVG creation**: 
  - Canva (canva.com)
  - Figma (figma.com) 
  - Inkscape (free desktop software)
- **Logo generators**:
  - Logo.com
  - Looka.com
  - Hatchful by Shopify

### Sample Dimensions:
- Favicon: 32x32px or 64x64px
- Header logo: 120x40px to 200x50px (horizontal)
- Square logo: 120x120px

## Technical Details

### Files Modified:
1. `includes/functions.php` - Added `get_site_settings()` helper function
2. `includes/header.php` - Updated to display logo in public navbar
3. `admin/partials/header.php` - Updated to display logo in admin navbar
4. `css/style.css` - Added `.site-logo` and `.admin-logo` styles
5. `admin/settings.php` - Enhanced logo upload with validation

### CSS Classes:
- `.site-logo` - Public site logo styling (40px height)
- `.admin-logo` - Admin panel logo styling (36px height)
- Both include `filter: brightness(1.1)` to enhance visibility

### Logo Storage:
- Logos are stored in: `images/` directory
- Settings stored in: `config/site-settings.json`
- Supports only one active logo at a time (previous uploads are deleted)

## Troubleshooting

### Logo Not Displaying?
1. Check file exists: `images/logo.svg`
2. Verify settings: Check `config/site-settings.json`
3. Check file permissions: Logo file should be readable
4. Clear browser cache: Ctrl+F5 or Cmd+Shift+R

### Logo Upload Failed?
1. Verify file size is under 2MB
2. Check file format (JPG, PNG, SVG, WEBP only)
3. Ensure `images/` directory is writable
4. Check PHP upload settings (`upload_max_filesize` in php.ini)

### Logo Too Large/Small?
1. Adjust CSS in `css/style.css`:
   ```css
   .site-logo {
       height: 40px;  /* Adjust this value */
   }
   ```

## Future Enhancements (Optional)

Consider adding:
- Separate logos for light/dark themes
- Favicon generation from logo
- Logo variations for different contexts
- Mobile-specific logo sizing
- Logo animation on page load

## Questions?

For technical support:
- Check browser console for errors (F12)
- Review PHP error logs
- Ensure all files have proper permissions

---

**Created:** March 7, 2026  
**Version:** 1.0.0
