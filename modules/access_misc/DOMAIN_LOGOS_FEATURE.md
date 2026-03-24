# Domain Logos Feature

This feature provides a configuration form to manage logo URLs for each domain and a token system to retrieve them.

## Configuration Form

### Location
Navigate to: `/admin/config/domain/logos`

Or access via the "Domain logos" tab in the Domain administration section (`/admin/config/domain`)

### Permission Required
- `administer domains`

### Usage
The form displays a text field for each domain in your Drupal site. Enter the URL or path to the logo for each domain. The values are stored in the `access_misc.domain_logos` configuration.

## Token System

### Token Name
`[access_misc:domain_logo]`

### Description
This token returns the configured logo URL for the current active domain.

### Usage Examples

#### In Content/Blocks
Simply use the token `[access_misc:domain_logo]` in any text field that supports token replacement.

#### In HTML
```html
<img src="[access_misc:domain_logo]" alt="Domain Logo">
```

#### In Twig Templates
```twig
{% set domain_logo_token = '[access_misc:domain_logo]' %}
<img src="{{ domain_logo_token|token_replace }}" alt="Domain Logo">
```

#### Programmatically
```php
$token_service = \Drupal::token();
$logo_url = $token_service->replace('[access_misc:domain_logo]', []);
```

## How It Works

1. The form allows administrators to configure logo URLs for each domain
2. Settings are saved to `access_misc.domain_logos` configuration
3. The token `[access_misc:domain_logo]` retrieves the logo URL based on the currently active domain
4. If no logo is configured for a domain, the token returns an empty string

## Files Created/Modified

### New Files
- `src/Form/DomainLogosForm.php` - Configuration form
- `config/schema/access_misc.schema.yml` - Configuration schema
- `access_misc.links.task.yml` - Tab integration with domain admin
- `DOMAIN_LOGOS_FEATURE.md` - This documentation

### Modified Files
- `access_misc.routing.yml` - Added route for `/admin/config/domain/logos`
- `access_misc.module` - Added token hooks and helper functions

## Technical Details

### Configuration Structure
```yaml
access_misc.domain_logos:
  logos:
    domain_id_1: 'https://example.com/logo1.png'
    domain_id_2: '/themes/custom/theme/logo2.svg'
    # etc...
```

### Token Implementation
The token is implemented using Drupal's token system via `hook_token_info()` and `hook_tokens()` in the `access_misc.module` file.

### Domain Detection
The active domain is detected using the Domain Access module's negotiator service (`domain.negotiator`).

## Testing

1. Clear cache: `ddev drush cr`
2. Navigate to `/admin/config/domain/logos`
3. Configure logo URLs for your domains
4. Test the token in a block or content: `[access_misc:domain_logo]`
5. Verify it returns the correct logo URL based on the current domain
