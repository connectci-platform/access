# ACCESS OpenAPI Specifications

This directory contains the OpenAPI 3.0.3 specifications for the ACCESS APIs.

## Files

- `announcements-api-2.2-openapi.yaml` - Announcements API v2.2 specification (YAML format)
- `announcements-api-2.2-openapi.json` - Announcements API v2.2 specification (JSON format)
- `events-api-2.2-openapi.yaml` - Events API v2.2 specification (YAML format)
- `events-api-2.2-openapi.json` - Events API v2.2 specification (JSON format)

## Usage

These specifications are automatically loaded by the OpenAPI generator plugins:

- `AccessAnnouncementsGenerator` - Serves announcements spec at `/openapi/access_announcements`
- `AccessEventsGenerator` - Serves events spec at `/openapi/access_events`

## Endpoints

- **Announcements API**: `/api/2.2/announcements`
- **Events API**: `/api/2.2/events`

## OpenAPI Documentation

- **JSON endpoints**: 
  - `/openapi/access_announcements`
  - `/openapi/access_events`

- **Swagger UI**:
  - `/admin/config/services/openapi/swagger/access_announcements`
  - `/admin/config/services/openapi/swagger/access_events`

## Maintenance

To update these specifications:

1. Edit the YAML or JSON files directly
2. Clear Drupal cache: `ddev drush cr`
3. The changes will be immediately reflected in the OpenAPI endpoints

## Version Control

These files are now version-controlled as part of the ACCESS module, ensuring they are:
- Backed up in git
- Deployed automatically
- Maintained alongside the codebase